<?php

namespace App\Services\Labs;

use App\Models\LabAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads from a lab on a user's behalf: medvault_lab_api_get(),
 * medvault_lab_requests() and medvault_lab_results().
 *
 * Every consumer goes through get(), which re-issues an expired lab token
 * once and replays. A bare HTTP call with a stored token is how a lab's
 * token expiry turns into users being signed out.
 */
final class LabApi
{
    /** No response at all: timeout, DNS, refused connection. */
    public const HTTP_FAILED = 'http_request_failed';

    public function __construct(private readonly LabTokenService $tokens) {}

    /**
     * GETs one of the driver's paths, logging in first if there is no token
     * and once more on a 401/403. A second rejection is returned as-is for
     * the caller to judge. Re-read the stored token afterwards if you need
     * it: it may have changed during this call.
     */
    public function get(User $user, LabConfig $cfg, string $pathKey, array $query = []): Response|LabFailure
    {
        $token = (string) LabAccount::where('user_id', $user->id)->where('lab_id', $cfg->labId)->first()?->external_token;

        if ($token === '') {
            $refreshed = $this->tokens->refresh($user, $cfg);
            if ($refreshed instanceof LabFailure) {
                return $refreshed;
            }
            $token = $refreshed['token'];
        }

        $response = $this->send($cfg, $pathKey, $query, $token);
        if ($response instanceof LabFailure || ! in_array($response->status(), [401, 403], true)) {
            return $response;
        }

        $refreshed = $this->tokens->refresh($user, $cfg);
        if ($refreshed instanceof LabFailure) {
            return $refreshed;
        }

        return $this->send($cfg, $pathKey, $query, $refreshed['token']);
    }

    /**
     * One lab's request list, normalized.
     *
     * @return list<array<string, mixed>>|LabFailure
     */
    public function requests(User $user, LabConfig $cfg): array|LabFailure
    {
        $response = $this->get($user, $cfg, 'requests_path');
        if ($response instanceof LabFailure) {
            return $response;
        }
        if ($response->status() !== 200) {
            return new LabFailure(LabFailure::UNREACHABLE, "Lab system returned HTTP {$response->status()}.");
        }

        $rows = LabTokenService::decode($response)[$cfg->driver['requests_key']] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach (LabRows::flatten($rows) as $row) {
            $normalized = LabRows::request($row, $cfg, (int) $user->id);
            if ($normalized['request_nb'] !== '') {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * One order's result rows. An empty list is normal, not an error: at some
     * facilities most orders are in that state.
     *
     * @return list<array<string, mixed>>|LabFailure
     */
    public function results(User $user, LabConfig $cfg, string $requestNb): array|LabFailure
    {
        $response = $this->get($user, $cfg, 'results_path', ['requestnb' => $requestNb]);
        if ($response instanceof LabFailure) {
            return $response;
        }
        if ($response->status() === 404) {
            return new LabFailure('result_not_found', 'These results are not available.', 404);
        }
        if ($response->status() !== 200) {
            return new LabFailure(LabFailure::UNREACHABLE, "Lab system returned HTTP {$response->status()}.");
        }

        $body = LabTokenService::decode($response);
        $rows = is_array($body) ? ($body[$cfg->driver['results_key']] ?? $body['data'] ?? []) : [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = LabRows::result($row, $cfg);
            }
        }

        return $out;
    }

    /** Whether this lab has refused this account often enough to stop asking. */
    public function hasGivenUp(User $user, LabConfig $cfg): bool
    {
        $failures = (int) LabAccount::where('user_id', $user->id)->where('lab_id', $cfg->labId)->value('auth_failures');

        return $failures >= (int) config('medvault.labs.max_auth_failures');
    }

    /** A plain GET to a URL the server built itself, with the lab's headers. */
    public function fetch(LabConfig $cfg, string $url): Response|LabFailure
    {
        try {
            return Http::timeout($cfg->timeout)->withHeaders($cfg->headers)->get($url);
        } catch (ConnectionException $e) {
            return new LabFailure(self::HTTP_FAILED, $e->getMessage(), 500);
        }
    }

    private function send(LabConfig $cfg, string $pathKey, array $query, string $token): Response|LabFailure
    {
        return $this->fetch($cfg, $cfg->url($pathKey, [...$query, $cfg->driver['token_param'] => $token]));
    }
}

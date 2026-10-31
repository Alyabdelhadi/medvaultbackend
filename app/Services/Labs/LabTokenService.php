<?php

namespace App\Services\Labs;

use App\Models\LabAccount;
use App\Models\User;
use App\Support\Mobile;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lab logins: medvault_refresh_lab_token() and medvault_refresh_all_lab_tokens().
 *
 * This is the single place that logs in to a lab. The login is one POST:
 * JSON, "username" as a JSON string (a leading zero is not a valid JSON
 * number), the account type, and no password. That is what lets an expired
 * token be re-issued silently instead of sending the user to the login screen.
 */
final class LabTokenService
{
    public function __construct(private readonly LabRegistry $labs) {}

    /**
     * Re-issues one lab's token.
     *
     * $mobileOverride is the number the caller just authenticated with, so
     * a fresh login does not trust the stored copy; it is raw input and is
     * shaped here per the lab's username format.
     *
     * @return array{token: string, lab_name: string, lab_id: int}|LabFailure
     */
    public function refresh(User $user, LabConfig $cfg, string $mobileOverride = ''): array|LabFailure
    {
        $raw = $mobileOverride !== '' ? $mobileOverride : (string) $user->mobile_number;
        $username = $this->username($cfg, $raw);

        if ($username === '') {
            return new LabFailure(LabFailure::NO_MOBILE, 'No mobile number on file for this account.', 400);
        }

        // Every attempt made while the lab's window is full both fails and
        // refills it, so once tripped, the whole site skips the call.
        if (Cache::has(self::backoffKey($cfg->labId))) {
            return $this->rateLimited();
        }

        try {
            $response = Http::timeout($cfg->timeout)
                ->withHeaders(['Content-Type' => 'application/json', ...$cfg->headers])
                ->withBody(json_encode(['username' => $username, 'type' => $user->functionType()]), 'application/json')
                ->post($cfg->url('login_path'));
        } catch (ConnectionException) {
            return new LabFailure(LabFailure::UNREACHABLE, 'Could not connect to the lab system. Please try again later.');
        }

        $status = $response->status();

        // Throttled or down is not a refused account. Counting either as one
        // would park every user behind a temporary outage.
        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After');
            $this->backOff($cfg, $retryAfter > 0
                ? min($retryAfter, (int) config('medvault.labs.max_backoff_seconds'))
                : (int) config('medvault.labs.backoff_seconds'));

            return $this->rateLimited();
        }

        if ($status >= 500) {
            return new LabFailure(LabFailure::UNREACHABLE, 'The lab system is busy. Please try again later.');
        }

        $body = self::decode($response);

        // A throttle that arrives as a 200 is still a throttle.
        if (self::isThrottled($body)) {
            $this->backOff($cfg, (int) config('medvault.labs.backoff_seconds'));

            return $this->rateLimited();
        }

        if (empty($body['success']) || empty($body['token'])) {
            // Answered and refused. Counted per lab, so a dead account stops
            // being retried without parking the user at the labs that know them.
            $this->account($user, $cfg->labId)->increment('auth_failures');

            return new LabFailure(LabFailure::REFUSED, 'The lab system did not issue a token for this account.');
        }

        $token = Sanitize::textField($body['token']);

        $account = $this->account($user, $cfg->labId);
        $account->forceFill(['external_token' => $token, 'poll_error' => null, 'auth_failures' => 0]);
        // Who the lab says this account is. Kept only when sent, so a lab
        // that omits it does not erase an earlier one.
        $holder = Sanitize::textField(is_string($body['name'] ?? null) ? $body['name'] : '');
        if ($holder !== '') {
            $account->holder_name = $holder;
        }
        $account->save();

        // The configured lab is the authority; the name the API reports is
        // used only for the legacy unconfigured account.
        $reported = Sanitize::textField($body['Lab'] ?? '');
        $labId = $cfg->labId === 0 ? (int) ($this->labs->findIdByName($reported) ?? 0) : $cfg->labId;

        return [
            'token' => $token,
            'lab_name' => $cfg->labId === 0 && $reported !== '' ? $reported : $cfg->labName,
            'lab_id' => $labId,
        ];
    }

    /**
     * Logs in at every active lab. A failure at one is never fatal: most
     * patients are known to exactly one facility.
     *
     * $errors is filled per lab because "refused" and "unreachable" lead to
     * opposite decisions upstream and cannot be told apart afterwards.
     *
     * @param  list<array{lab_id: int, code: string, message: string}>  $errors
     * @return list<array{lab_id: int, lab_name: string, token: string, caps: array}>
     */
    public function refreshAll(User $user, string $mobileOverride = '', ?array &$errors = []): array
    {
        $issued = [];
        $errors = [];

        foreach ($this->labs->active() as $cfg) {
            $result = $this->refresh($user, $cfg, $mobileOverride);
            if ($result instanceof LabFailure) {
                $errors[] = ['lab_id' => $cfg->labId, 'code' => $result->code, 'message' => $result->message];

                continue;
            }

            $issued[] = [
                'lab_id' => $result['lab_id'],
                'lab_name' => $result['lab_name'],
                'token' => $result['token'],
                'caps' => $cfg->caps,
            ];
        }

        return $issued;
    }

    /** medvault_lab_username(): how this lab spells the mobile number. */
    public function username(LabConfig $cfg, string $raw): string
    {
        return match ($cfg->mobileFormat) {
            'e164' => Mobile::normalize($raw),
            'national' => Mobile::split($raw)['national'],
            default => Mobile::forLab($raw),
        };
    }

    /** Decodes a lab body, stripping a UTF-8 BOM that would make json_decode return null. */
    public static function decode(Response $response): ?array
    {
        $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $response->body()), true);

        return is_array($data) ? $data : null;
    }

    /** Whether a body is the lab's rate limiter talking, whatever the status. */
    public static function isThrottled(?array $body): bool
    {
        $message = strtolower(trim((string) ($body['message'] ?? '')));
        if ($message === '') {
            return false;
        }
        foreach ((array) config('medvault.labs.throttle_phrases') as $phrase) {
            if (str_contains($message, strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    public static function backoffKey(int $labId): string
    {
        return 'medvault_lab_backoff_'.$labId;
    }

    private function backOff(LabConfig $cfg, int $seconds): void
    {
        Cache::put(self::backoffKey($cfg->labId), 1, $seconds);
    }

    private function rateLimited(): LabFailure
    {
        return new LabFailure(LabFailure::RATE_LIMITED, 'The lab system is busy. Please try again shortly.');
    }

    private function account(User $user, int $labId): LabAccount
    {
        return LabAccount::firstOrCreate(['user_id' => $user->id, 'lab_id' => $labId]);
    }
}

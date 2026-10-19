<?php

namespace App\Services\Notifications;

use App\Models\Setting;
use App\Support\HttpErrors;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * FCM HTTP v1, from medvault_fcm_send_raw() and its helpers.
 *
 * Credentials are the service-account JSON pasted into Firebase Settings,
 * stored encrypted as the `firebase_credentials` setting. The access token is
 * cached for most of its hour rather than fetched for every message.
 */
class Fcm
{
    public const TOPIC = 'medvault';

    private const CHANNEL = 'medvault_notifications';

    /**
     * Posts one already-built `message` object.
     *
     * @return array{success: bool, message: string, code: int, error_status: string}
     */
    public function sendRaw(array $message): array
    {
        $credentials = $this->credentials();
        $projectId = (string) ($credentials['project_id'] ?? '');
        if ($projectId === '') {
            return $this->failure('Firebase project ID missing. Check your credentials JSON.');
        }

        $accessToken = $this->accessToken($credentials);
        if (! is_string($accessToken) || $accessToken === '') {
            return $this->failure(is_string($accessToken) ? 'Failed to get FCM access token.' : $accessToken['error']);
        }

        try {
            $response = Http::timeout(15)->withToken($accessToken)->asJson()
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", ['message' => $message]);
        } catch (ConnectionException $e) {
            return $this->failure(HttpErrors::safeMessage($e));
        }

        if ($response->status() === 200) {
            return ['success' => true, 'message' => 'Sent.', 'code' => 200, 'error_status' => ''];
        }

        $body = $response->json();

        return [
            'success' => false,
            'message' => (string) ($body['error']['message'] ?? $response->body()),
            'code' => $response->status(),
            'error_status' => (string) ($body['error']['status'] ?? ''),
        ];
    }

    /** The broadcast every install subscribes to. */
    public function sendToTopic(string $title, string $body): array
    {
        $result = $this->sendRaw([
            'topic' => self::TOPIC,
            'notification' => ['title' => $title, 'body' => $body],
            'android' => ['priority' => 'high', 'notification' => ['channel_id' => self::CHANNEL]],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ]);

        return $result['success']
            ? ['success' => true, 'message' => 'Notification sent to all subscribed users.']
            : ['success' => false, 'message' => "FCM error ({$result['code']}): {$result['message']}"];
    }

    /** One device. Data values must be strings; FCM rejects anything else. */
    public function deviceMessage(string $token, string $title, string $body, array $data = []): array
    {
        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'android' => ['priority' => 'high', 'notification' => ['channel_id' => self::CHANNEL]],
            'apns' => ['payload' => ['aps' => ['sound' => 'default', 'badge' => 1]]],
        ];
        if ($data !== []) {
            $message['data'] = array_map('strval', $data);
        }

        return $message;
    }

    private function credentials(): array
    {
        try {
            $json = Setting::secret('firebase_credentials');
        } catch (Throwable) {
            return [];
        }
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return string|array{error: string} */
    private function accessToken(array $credentials): string|array
    {
        if (empty($credentials['private_key']) || empty($credentials['client_email'])) {
            return ['error' => 'Firebase credentials are not configured. Go to Push Notifications → Firebase Settings.'];
        }

        $cacheKey = 'fcm_access_token_'.md5($credentials['client_email'].$credentials['private_key']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $key = openssl_pkey_get_private($credentials['private_key']);
        if ($key === false) {
            return ['error' => 'Invalid private key in Firebase credentials.'];
        }
        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

        try {
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $input.'.'.$b64($signature),
            ]);
        } catch (ConnectionException $e) {
            return ['error' => $e->getMessage()];
        }

        $token = (string) $response->json('access_token', '');
        if ($token === '') {
            $err = $response->json('error_description') ?? $response->json('error') ?? 'Unknown error';

            return ['error' => "Failed to get FCM access token: {$err}"];
        }

        Cache::put($cacheKey, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'code' => 0, 'error_status' => ''];
    }
}

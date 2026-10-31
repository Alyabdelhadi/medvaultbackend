<?php

namespace App\Services\Accounts;

use App\Models\AppSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The app's bearer tokens: 64 hex characters, one per signed-in device, so
 * signing in on a second phone leaves the first signed in. Only the sha256
 * is stored, so a database leak does not hand out sessions.
 *
 * WordPress kept one token per account in users.api_token_hash. A token
 * issued that way still works: it becomes a session the first time it is
 * used, so nobody is signed out by the move.
 */
final class ApiTokens
{
    /** Sessions an account keeps; the least recently used go first. */
    public const MAX_SESSIONS = 10;

    /** last_used_at is written at most this often, not on every request. */
    private const TOUCH_EVERY_SECONDS = 300;

    /**
     * A new session for this device. $request supplies what the app says
     * about the device (device_name, platform, app_version) and the IP.
     */
    public function issue(User $user, ?Request $request = null): string
    {
        $token = bin2hex(random_bytes(32));
        AppSession::create([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            ...self::deviceFields($request),
            'last_used_at' => now(),
        ]);

        $stale = AppSession::where('user_id', $user->id)
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->skip(self::MAX_SESSIONS)->take(PHP_INT_MAX)->pluck('id');
        if ($stale->isNotEmpty()) {
            AppSession::whereIn('id', $stale)->delete();
        }

        return $token;
    }

    /** Signs every device out: a password change or reset, or an admin. */
    public function revoke(User $user): void
    {
        AppSession::where('user_id', $user->id)->delete();
        if ($user->api_token_hash !== null) {
            $user->forceFill(['api_token_hash' => null])->save();
        }
    }

    public function resolve(string $token): ?User
    {
        return $this->session($token)?->user;
    }

    /** The session a token belongs to, touched as used. */
    public function session(string $token): ?AppSession
    {
        if ($token === '') {
            return null;
        }
        $hash = self::hash($token);

        $session = AppSession::with('user')->where('token_hash', $hash)->first();
        if ($session === null) {
            $session = $this->adoptLegacy($hash);
        }
        if ($session === null || $session->user === null) {
            return null;
        }

        if ($session->last_used_at === null || $session->last_used_at->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS) {
            $session->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $session;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A token from the one-per-account days, moved into a session row. */
    private function adoptLegacy(string $hash): ?AppSession
    {
        $user = User::where('api_token_hash', $hash)->first();
        if ($user === null) {
            return null;
        }

        $session = AppSession::create(['user_id' => $user->id, 'token_hash' => $hash, 'last_used_at' => now()]);
        $user->forceFill(['api_token_hash' => null])->saveQuietly();

        return $session->setRelation('user', $user);
    }

    private static function deviceFields(?Request $request): array
    {
        if ($request === null) {
            return [];
        }
        $text = fn (string $key, int $max) => mb_substr(trim(strip_tags((string) $request->input($key, ''))), 0, $max) ?: null;

        return [
            'device_name' => $text('device_name', 120),
            'platform' => ($p = strtolower((string) $text('platform', 20))) !== '' ? $p : null,
            'app_version' => $text('app_version', 40),
            'ip' => $request->ip(),
        ];
    }
}

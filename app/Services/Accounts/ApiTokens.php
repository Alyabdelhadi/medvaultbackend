<?php

namespace App\Services\Accounts;

use App\Models\User;

/**
 * The app's bearer token: 64 hex characters, one per account, replaced on
 * every login and password change, as in WordPress. Only its sha256 is
 * stored, so a database leak does not hand out sessions.
 */
final class ApiTokens
{
    public function issue(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $user->forceFill(['api_token_hash' => self::hash($token)])->save();

        return $token;
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['api_token_hash' => null])->save();
    }

    public function resolve(string $token): ?User
    {
        return $token === '' ? null : User::where('api_token_hash', self::hash($token))->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

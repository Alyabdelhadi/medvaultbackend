<?php

namespace App\Services\Accounts;

use App\Models\User;
use App\Support\Mobile;

/**
 * Finds accounts the way the plugin did, so every spelling of a number and
 * every casing of a username resolves to the same account.
 */
final class UserLookup
{
    /**
     * medvault_find_user_by_mobile(): canonical E.164 first, then the
     * Lebanese local digits, then the raw spellings older accounts were
     * stored under. Matches found the slow way are re-indexed so they only
     * cost that once. Ties go to the lowest username, as get_users() ordered.
     */
    public function byMobile(string $raw): ?User
    {
        $parts = Mobile::split($raw);
        if ($parts['e164'] === '') {
            return null;
        }

        $user = User::where('mobile_e164', $parts['e164'])->orderBy('username')->first();
        if ($user !== null) {
            return $user;
        }

        if ($parts['cc'] === Mobile::DEFAULT_CC) {
            $user = User::where('mobile_local', $parts['national'])->orderBy('username')->first();
            if ($user !== null) {
                return $this->reindex($user, (string) ($user->mobile_number ?: $raw));
            }
        }

        $user = User::whereIn('mobile_number', Mobile::variants($parts['cc'], $parts['national']))
            ->orderBy('username')
            ->first();

        return $user === null ? null : $this->reindex($user, (string) $user->mobile_number);
    }

    /** username_exists(). Case-insensitive, as MySQL's collation made it. */
    public function usernameExists(string $username): bool
    {
        return User::whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->exists();
    }

    /** email_exists(): the owner's id, or null. */
    public function emailOwner(string $email): ?int
    {
        if ($email === '') {
            return null;
        }
        $id = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->value('id');

        return $id === null ? null : (int) $id;
    }

    /** medvault_generate_username(): name with the non-alphanumerics removed, suffixed until free. */
    public function generateUsername(string $first, string $last, string $fallback = ''): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $first.$last));
        if ($base === '') {
            $base = $fallback;
        }
        if ($base === '') {
            return '';
        }

        $username = $base;
        $suffix = 1;
        while ($this->usernameExists($username)) {
            $username = $base.($suffix++);
        }

        return $username;
    }

    private function reindex(User $user, string $raw): User
    {
        $user->forceFill(Mobile::indexColumns($raw))->save();

        return $user;
    }
}

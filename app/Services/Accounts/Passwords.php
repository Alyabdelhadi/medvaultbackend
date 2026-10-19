<?php

namespace App\Services\Accounts;

use App\Models\User;
use App\Support\WordPress\WordPressPassword;
use Illuminate\Support\Facades\Hash;

/**
 * Password checks for accounts that may still hold a WordPress hash.
 *
 * A successful check against a WordPress-format hash replaces it with plain
 * bcrypt, so each imported account converts on its first sign-in here and
 * WordPress's formats can eventually be retired.
 */
final class Passwords
{
    public function check(User $user, string $password): bool
    {
        $hash = (string) $user->password;

        if (! WordPressPassword::isWordPressHash($hash)) {
            if (! password_verify($password, $hash)) {
                return false;
            }
            if (Hash::needsRehash($hash)) {
                $this->rehash($user, $password);
            }

            return true;
        }

        if (! WordPressPassword::check($password, $hash)) {
            return false;
        }

        $this->rehash($user, $password);

        return true;
    }

    /** Stores the password exactly as it just verified. */
    private function rehash(User $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)])->save();
    }

    /**
     * A newly chosen password. wp_hash_password() trimmed before hashing. Done here too so that a
     * password set on either server verifies the same way.
     */
    public function set(User $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make(trim($password))])->save();
    }
}

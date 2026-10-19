<?php

namespace App\Support\WordPress;

/**
 * Verifies passwords against hashes written by WordPress, so imported
 * accounts sign in with the password they already have.
 *
 * A port of wp_check_password() from WordPress 6.8+, which accepts four
 * formats depending on when the password was last set:
 *
 *   $wp$2y$…  6.8+: bcrypt over base64(HMAC-SHA384(password, "wp-sha384"))
 *   $P$…      phpass portable hashes, everything before 6.8
 *   $2y$…     plain bcrypt, which needs no special handling
 *   32 hex    md5, from very old installs
 *
 * wp_hash_password() trims before hashing but wp_check_password() does not,
 * so neither does this. Mirroring that exactly is the point: a password that
 * worked on WordPress works here, and one that did not, does not.
 */
final class WordPressPassword
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Whether $hash is in a WordPress-only format that should be replaced. */
    public static function isWordPressHash(string $hash): bool
    {
        return str_starts_with($hash, '$wp')
            || str_starts_with($hash, '$P$')
            || str_starts_with($hash, '$H$')
            || (strlen($hash) <= 32 && $hash !== '');
    }

    public static function check(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        if (strlen($hash) <= 32) {
            return hash_equals($hash, md5($password));
        }

        if (strlen($password) > 4096) {
            return false;
        }

        if (str_starts_with($hash, '$wp')) {
            $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

            return password_verify($prehashed, substr($hash, 3));
        }

        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, self::phpass($password, $hash));
        }

        return password_verify($password, $hash);
    }

    /** phpass crypt_private(), portable-hash branch. Returns '*0' on a bad setting. */
    private static function phpass(string $password, string $setting): string
    {
        $countLog2 = strpos(self::ITOA64, $setting[3] ?? '');
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return '*0';
        }

        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return '*0';
        }

        $count = 1 << $countLog2;
        $hash = md5($salt.$password, true);
        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).self::encode64($hash, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}

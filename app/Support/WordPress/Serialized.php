<?php

namespace App\Support\WordPress;

/**
 * Reads values WordPress stored with maybe_serialize().
 *
 * Arrays in usermeta, postmeta and options (seen-status maps, FCM token
 * lists, slides, role capabilities) are PHP-serialized. Objects are refused
 * outright: the import reads a database, and a serialized object in it is
 * either corruption or an attack, never data we need.
 */
final class Serialized
{
    public static function decode(mixed $value): mixed
    {
        if (! is_string($value) || ! self::looksSerialized($value)) {
            return $value;
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);

        // "b:0;" legitimately decodes to false; anything else that does is a
        // value that failed to parse, which is kept as the raw string.
        if ($decoded === false && $value !== 'b:0;') {
            return $value;
        }

        return $decoded;
    }

    /** is_serialized(), strict mode. */
    public static function looksSerialized(string $data): bool
    {
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (strlen($data) < 4 || $data[1] !== ':') {
            return false;
        }

        $last = substr($data, -1);
        if ($last !== ';' && $last !== '}') {
            return false;
        }

        return match ($data[0]) {
            's' => (bool) preg_match('/^s:\d+:".*";$/s', $data),
            'a', 'O' => (bool) preg_match('/^'.$data[0].':\d+:/s', $data),
            'b', 'i', 'd' => (bool) preg_match('/^'.$data[0].':[\d.E+-]+;$/', $data),
            default => false,
        };
    }
}

<?php

namespace App\Support;

/**
 * How first and last names are stored: the first letter of each word is a
 * capital ("jean-paul el haddad" → "Jean-Paul El Haddad"); the rest is kept
 * as typed, so "McKenzie" stays as written. Arabic script has no case and
 * passes through unchanged.
 */
final class PersonName
{
    public static function capitalize(string $name): string
    {
        return preg_replace_callback('/(^|[\s\-])(\p{Ll})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $name);
    }
}

<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A key => value map stored as a JSON object, never a list.
 *
 * Laravel's 'array' cast writes [0 => 'V', 1 => 'F'] as ["V","F"], which
 * reads back with the keys renumbered. For a seen-status map keyed on
 * request numbers that is silent corruption, so maps always go out as {}.
 * Null stays null: for seen_status it means "not seeded yet".
 */
class JsonMap implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : (array) json_decode($value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode((object) $value);
    }
}

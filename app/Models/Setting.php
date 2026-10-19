<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Former wp_options. Values are stored JSON-encoded; secrets go through
 * putSecret()/secret() and are encrypted with APP_KEY.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);

        return $row === null ? $default : json_decode((string) $row->value, true);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    public static function secret(string $key, ?string $default = null): ?string
    {
        $row = static::query()->find($key);

        return $row === null || $row->value === null ? $default : Crypt::decryptString($row->value);
    }

    public static function putSecret(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
    }
}

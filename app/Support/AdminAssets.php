<?php

namespace App\Support;

/**
 * The admin's logo and favicon as data URIs. The admin is reached through
 * public_html/app/.htaccess, which routes pages to Laravel but no static
 * files, so the images travel inside the page.
 */
final class AdminAssets
{
    private static array $cache = [];

    public static function logo(): string
    {
        return self::dataUri('logo.png');
    }

    public static function favicon(): string
    {
        return self::dataUri('favicon.png');
    }

    private static function dataUri(string $file): string
    {
        return self::$cache[$file] ??= 'data:image/png;base64,'.base64_encode((string) @file_get_contents(resource_path('admin/'.$file)));
    }
}

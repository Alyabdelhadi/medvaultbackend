<?php

namespace App\Support;

/**
 * The part of an HTTP client error that may leave the server.
 *
 * Guzzle appends " (see https://curl.se/...) for <url>" to cURL errors. The
 * URL is the lab's own API address, which for a proxied facility is exactly
 * what must stay here, and it can carry a token in its query. WordPress
 * reported only "cURL error 28: Operation timed out after ...", and so does
 * this.
 */
final class HttpErrors
{
    public static function safeMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        $message = preg_replace('/\s*\(see https?:\/\/curl\.[^)]*\).*$/s', '', $message);

        return trim((string) preg_replace('/\s+for\s+https?:\/\/\S+.*$/s', '', (string) $message));
    }
}

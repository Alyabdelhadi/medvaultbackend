<?php

namespace App\Http\Controllers\Concerns;

use App\Http\WpError;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\Request;

/**
 * Parameter handling as WP_REST_Request did it: JSON body, then form body,
 * then query string; "required" means "not null", so an empty string passes
 * the route check and is caught by each handler's own validation.
 */
trait ReadsWordPressParams
{
    /** @param  list<string>  $keys */
    protected function requireParams(Request $request, array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $k) => $request->input($k) === null));
        if ($missing !== []) {
            throw WpError::missingParams($missing);
        }
    }

    /** A raw parameter, untouched, as get_param() returned it. */
    protected function param(Request $request, string $key): mixed
    {
        return $request->input($key);
    }

    /** sanitize_text_field(), then trim, for a parameter that may be absent. */
    protected function text(Request $request, string $key): string
    {
        return trim(Sanitize::textField($request->input($key) ?? ''));
    }

    /** A raw string parameter: passwords are never sanitized. */
    protected function raw(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }
}

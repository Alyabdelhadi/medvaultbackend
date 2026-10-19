<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A WP_Error, rendered the way the WordPress REST API rendered it:
 *
 *   {"code": "invalid_credentials", "message": "...", "data": {"status": 401}}
 *
 * The app branches on `code` (lab_account_invalid vs any other 401 decides
 * between "the lab dropped you" and "your session is gone") and shows
 * `message`, so both have to stay byte-for-byte what the plugin sent.
 */
class WpError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'data' => ['status' => $this->status, ...$this->extra],
        ], $this->status);
    }

    /** What WordPress answered for an unknown route or the wrong method. */
    public static function noRoute(): self
    {
        return new self('rest_no_route', 'No route was found matching the URL and request method.', 404);
    }

    /** What WordPress answered when a required argument was null or absent. */
    public static function missingParams(array $params): self
    {
        return new self(
            'rest_missing_callback_param',
            'Missing parameter(s): '.implode(', ', $params),
            400,
            ['params' => array_values($params)],
        );
    }

    public static function unauthorized(): self
    {
        return new self('unauthorized', 'Invalid or missing authentication token. Send: Authorization: Bearer <token>', 401);
    }
}

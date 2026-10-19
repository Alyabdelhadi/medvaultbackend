<?php

namespace App\Http\Middleware;

use App\Http\WpError;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Support\WordPress\Sanitize;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * api_check_token_permission(): "Authorization: Bearer <token>", exactly that
 * casing, or 401 unauthorized. The app treats any 401 without the
 * lab_account_invalid code as "the MedVault session is gone".
 */
class AuthenticateAppToken
{
    public function __construct(private readonly ApiTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = self::userFrom($request, $this->tokens);
        if ($user === null) {
            throw WpError::unauthorized();
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    public static function userFrom(Request $request, ApiTokens $tokens): ?User
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return $tokens->resolve(Sanitize::textField(trim(substr($header, 7))));
    }
}

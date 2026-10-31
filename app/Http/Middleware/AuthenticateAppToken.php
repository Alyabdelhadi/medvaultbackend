<?php

namespace App\Http\Middleware;

use App\Http\WpError;
use App\Models\AppSession;
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
        $session = self::sessionFrom($request, $this->tokens);
        if ($session === null) {
            throw WpError::unauthorized();
        }

        $user = $session->user;
        $request->setUserResolver(fn () => $user);
        // Which device this is, for the connected-devices list and logout.
        $request->attributes->set('app_session', $session);

        return $next($request);
    }

    public static function userFrom(Request $request, ApiTokens $tokens): ?User
    {
        return self::sessionFrom($request, $tokens)?->user;
    }

    public static function sessionFrom(Request $request, ApiTokens $tokens): ?AppSession
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return $tokens->session(Sanitize::textField(trim(substr($header, 7))));
    }
}

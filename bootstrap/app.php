<?php

use App\Http\Middleware\AuthenticateAppToken;
use App\Http\WpError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // The WordPress REST namespace the app already calls.
        apiPrefix: 'wp-json/custom/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // WordPress core's namespace, for the photo upload only.
        then: function () {
            Route::middleware('api')->prefix('wp-json/wp/v2')->group(base_path('routes/wp.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['app.token' => AuthenticateAppToken::class]);

        // WordPress neither trimmed input nor turned "" into null. Both matter:
        // passwords are compared as typed, "required" means "not null", and an
        // empty id_image or blood_type is how the app clears one.
        $wpApi = fn (Request $request) => $request->is('wp-json/*');
        TrimStrings::skipWhen($wpApi);
        ConvertEmptyStringsToNull::skipWhen($wpApi);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('wp-json/*');

        // WordPress answered an unknown route and a wrong method alike.
        $exceptions->render(function (NotFoundHttpException|MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            return $isApi($request) ? WpError::noRoute()->render() : null;
        });

        // Anything unexpected still answers in WordPress's error shape, so
        // the app gets a message it can show instead of an unparseable page.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request) || $e instanceof WpError || config('app.debug')) {
                return null;
            }

            return new JsonResponse([
                'code' => 'internal_server_error',
                'message' => 'There has been a critical error on this website.',
                'data' => ['status' => 500],
            ], 500);
        });

        $exceptions->dontReport(WpError::class);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request) || $request->expectsJson());
    })->create();

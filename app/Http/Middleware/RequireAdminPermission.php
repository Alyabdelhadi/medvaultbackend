<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `perm:labs,edit` on an admin route. `perm:slug,view` and `perm:kind,view`
 * take the page from the route's {slug} (patients, doctors, guarantors) or
 * {kind} (sliders, banners), which share routes.
 */
class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action = 'view'): Response
    {
        if (in_array($module, ['slug', 'kind'], true)) {
            $module = (string) $request->route($module);
        }

        abort_unless($request->user()?->canAdmin($module, $action), 403, 'You do not have permission to do that.');

        return $next($request);
    }
}

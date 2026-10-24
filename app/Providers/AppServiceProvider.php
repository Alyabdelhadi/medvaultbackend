<?php

namespace App\Providers;

use App\Http\WpError;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Labs\LabRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lab configs are memoised for one request (or one queued job), as
        // the plugin's static caches were, and never longer.
        $this->app->scoped(LabRegistry::class);

        // What this request changed, for the audit log; never carried over.
        $this->app->scoped(AuditTrail::class);
    }

    public function boot(): void
    {
        // The admin. Administrators get every page; everyone else gets what
        // their group grants, per page and action (App\Support\AdminModules).
        // Used as `can:perm,labs,edit` on routes and @can('perm', ...) in views.
        // Saves of the models the audit log watches. AuditTrail ignores them
        // unless the request asked it to watch (RecordAdminChanges does).
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $payload) use ($event) {
                if (($payload[0] ?? null) instanceof Model && isset(AuditTrail::WATCHED[$payload[0]::class])) {
                    app(AuditTrail::class)->collect($event, $payload[0]);
                }
            });
        }

        Gate::define('perm', fn (User $user, string $module, string $action = 'view') => $user->canAdmin($module, $action));

        // Anonymous photo uploads. Sign-up sends at most three; this is the
        // ceiling on a script filling the disk from one address.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute((int) config('medvault.uploads.per_minute'))
            ->by($request->ip())
            ->response(fn () => (new WpError('rest_too_many_requests', 'Too many uploads. Please wait a minute and try again.', 429))->render()));

        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('login')).'|'.$request->ip()));
    }
}

<?php

namespace App\Providers;

use App\Http\WpError;
use App\Services\Labs\LabRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lab configs are memoised for one request (or one queued job), as
        // the plugin's static caches were, and never longer.
        $this->app->scoped(LabRegistry::class);
    }

    public function boot(): void
    {
        // Anonymous photo uploads. Sign-up sends at most three; this is the
        // ceiling on a script filling the disk from one address.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute((int) config('medvault.uploads.per_minute'))
            ->by($request->ip())
            ->response(fn () => (new WpError('rest_too_many_requests', 'Too many uploads. Please wait a minute and try again.', 429))->render()));
    }
}

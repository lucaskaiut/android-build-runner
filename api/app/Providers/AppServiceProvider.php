<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Only administrators may edit executable build configuration.
        Gate::define('manage-platform', fn (\App\Models\User $user) => $user->isAdmin());

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('runner', function (Request $request) {
            $runner = $request->user();

            return Limit::perMinute(600)->by($runner ? 'runner:'.$runner->getAuthIdentifier() : 'ip:'.$request->ip());
        });
    }
}

<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session based authentication for the panel (Sanctum SPA flow).
        $middleware->statefulApi();

        // API consumers always talk JSON.
        $middleware->api(prepend: [
            App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'runner.active' => App\Http\Middleware\EnsureRunnerIsActive::class,
        ]);

        // GitHub webhooks authenticate with HMAC signatures, never with a session.
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);

        // Cloudflare Tunnel / Docker Desktop forward the original client IP.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('builds:reap')->everyMinute()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (App\Exceptions\BuildConflictException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    ...$exception->context,
                ], 409);
            }
        });
    })->create();

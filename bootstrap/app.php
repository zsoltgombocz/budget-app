<?php

use App\Http\Middleware\DevGate;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\SetUserLocale;
use App\Http\Middleware\TrackUserActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only reachable through the Caddy container (and Cloudflare), never directly.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            DevGate::class,
            TrackUserActivity::class,
            SetUserLocale::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'push/no-spend/*',
        ]);

        // The monitoring dashboard belongs to the admin panel, so its guests go to the admin login.
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is(trim(config()->string('pulse.path', 'pulse'), '/').'*')
            ? route('filament.admin.auth.login')
            : route('login'));

        $middleware->alias([
            'onboarded' => EnsureOnboarded::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

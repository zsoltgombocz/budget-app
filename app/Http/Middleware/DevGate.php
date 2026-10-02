<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts a shared password in front of the dev stack and keeps it out of search engines.
 * Passing it once leaves a year-long cookie, so the installed app does not ask again.
 */
class DevGate
{
    public const string COOKIE = 'dev_gate';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $password = self::password();

        if ($password === null) {
            return $next($request);
        }

        $cookie = $request->cookie(self::COOKIE);
        $passed = is_string($cookie) && hash_equals(self::cookieValue($password), $cookie);

        $response = $passed || $request->routeIs('dev-gate', 'dev-gate.check')
            ? $next($request)
            : redirect()->guest(route('dev-gate'));

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * The configured gate password, or null when the gate is off.
     */
    public static function password(): ?string
    {
        $password = config('budget.dev_gate_password');

        return is_string($password) && $password !== '' ? $password : null;
    }

    public static function cookieValue(string $password): string
    {
        return hash_hmac('sha256', 'dev-gate', $password.config()->string('app.key'));
    }
}

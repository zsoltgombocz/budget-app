<?php

namespace App\Http\Controllers;

use App\Http\Middleware\DevGate;
use App\Support\SecurityEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DevGateController extends Controller
{
    public function show(): View
    {
        return view('dev-gate');
    }

    public function check(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $key = 'dev-gate:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['password' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $password = DevGate::password();

        if ($password === null || ! hash_equals($password, (string) $request->string('password'))) {
            RateLimiter::hit($key, 600);
            SecurityEvents::record('wrong_dev_password');

            throw ValidationException::withMessages(['password' => __('Wrong password.')]);
        }

        RateLimiter::clear($key);

        return redirect()->intended(route('dashboard'))
            ->withCookie(cookie()->forever(DevGate::COOKIE, DevGate::cookieValue($password)));
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use App\Support\SecurityEvents;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-in links. Opening the link only shows a button; the token is used up by the POST,
 * so mail scanners that prefetch links cannot spend it.
 */
class MagicLinkController extends Controller
{
    public function show(string $token): View
    {
        return view('pages::auth.magic-link', ['token' => $token]);
    }

    public function login(Request $request, string $token, SendMagicLink $magicLinks): RedirectResponse
    {
        $user = $magicLinks->consume($token);

        if (! $user instanceof User) {
            SecurityEvents::record('invalid_link');

            return to_route('login')->with('status', __('This sign-in link has expired or was already used. Request a new one.'));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}

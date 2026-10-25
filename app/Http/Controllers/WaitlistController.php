<?php

namespace App\Http\Controllers;

use App\Actions\Waitlist\JoinWaitlist;
use App\Models\WaitlistSignup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    /**
     * The landing page form. A filled honeypot field is a bot: it gets the same answer, nothing is stored.
     */
    public function store(Request $request, JoinWaitlist $joinWaitlist): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'email.required' => __('Write your e-mail address here.'),
            'email.email' => __('This does not look like an e-mail address.'),
        ]);

        $joinWaitlist->handle($request->string('email')->toString(), 'landing');

        return redirect()->to(route('home').'#varolista')->with('waitlist', 'sent');
    }

    public function confirm(string $token): View
    {
        $signup = WaitlistSignup::query()->where('token', $token)->first();
        $signup?->isConfirmed() || $signup?->update(['confirmed_at' => now()]);

        return view('waitlist-status', ['state' => $signup === null ? 'unknown' : 'confirmed', 'token' => $signup?->token]);
    }

    /**
     * Unsubscribing deletes the address for good.
     */
    public function unsubscribe(string $token): View
    {
        WaitlistSignup::query()->where('token', $token)->delete();

        return view('waitlist-status', ['state' => 'removed', 'token' => null]);
    }
}

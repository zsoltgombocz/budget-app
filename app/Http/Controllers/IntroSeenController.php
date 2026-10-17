<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The "How it works" tour was finished or skipped: it no longer opens by itself after
 * onboarding. Replaying it from Settings keeps the date it was first seen.
 */
class IntroSeenController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $settings = $user->settings();

        if ($settings->intro_seen_at === null) {
            $settings->update(['intro_seen_at' => now()]);
        }

        return response()->noContent();
    }
}

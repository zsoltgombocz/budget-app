<?php

namespace App\Http\Middleware;

use App\Models\CaptureToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs the request in as the owner of the automatic capture key in the
 * "Authorization: Bearer msc_…" header. No session, no cookies.
 */
class AuthenticateCaptureToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();

        $token = str_starts_with($plain, CaptureToken::PREFIX)
            ? CaptureToken::query()->withoutGlobalScopes()->where('token_hash', CaptureToken::hash($plain))->first()
            : null;

        $user = $token === null ? null : User::query()->find($token->user_id);

        if (! $token instanceof CaptureToken || ! $user instanceof User) {
            return response()->json(['status' => 'unauthorized', 'message' => __('Unknown or deleted key.')], 401);
        }

        if ($user->isDisabled()) {
            return response()->json(['status' => 'forbidden', 'message' => __('This account is disabled.')], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        Auth::setUser($user);
        App::setLocale($user->settings()->locale);

        return $next($request);
    }
}

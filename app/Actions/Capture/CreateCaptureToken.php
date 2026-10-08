<?php

namespace App\Actions\Capture;

use App\Models\CaptureToken;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CreateCaptureToken
{
    public const int MAX_TOKENS = 5;

    /**
     * Create a key for the phone automation. Returns the plain key: it is shown once and
     * only its hash is stored.
     */
    public function handle(User $user, string $name): string
    {
        if ($user->captureTokens()->count() >= self::MAX_TOKENS) {
            throw ValidationException::withMessages(['name' => __('You can have at most :count keys. Delete one first.', ['count' => self::MAX_TOKENS])]);
        }

        $plain = CaptureToken::PREFIX.Str::random(40);

        $user->captureTokens()->create([
            'name' => Str::limit(trim($name), 60, '') ?: 'Phone',
            'token_hash' => CaptureToken::hash($plain),
        ]);

        return $plain;
    }
}

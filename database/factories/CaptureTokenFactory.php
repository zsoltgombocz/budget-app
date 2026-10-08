<?php

namespace Database\Factories;

use App\Models\CaptureToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CaptureToken>
 */
class CaptureTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'iPhone',
            'token_hash' => CaptureToken::hash(CaptureToken::PREFIX.Str::random(40)),
        ];
    }

    /**
     * A token whose plain value is known to the test.
     */
    public function plain(string $plainToken): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_hash' => CaptureToken::hash($plainToken),
        ]);
    }
}

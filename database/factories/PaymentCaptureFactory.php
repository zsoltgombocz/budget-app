<?php

namespace Database\Factories;

use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Enums\PaymentKind;
use App\Models\PaymentCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentCapture>
 */
class PaymentCaptureFactory extends Factory
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
            'status' => CaptureStatus::Recorded,
            'kind' => PaymentKind::Purchase,
            'amount' => fake()->numberBetween(500, 20_000),
            'currency' => 'HUF',
            'merchant' => fake()->company(),
            'platform' => 'ios',
            'occurred_at' => now(),
        ];
    }

    public function pending(CaptureReason $reason): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CaptureStatus::Pending,
            'reason' => $reason,
        ]);
    }
}

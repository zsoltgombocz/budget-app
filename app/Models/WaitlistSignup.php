<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WaitlistSignupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Someone who asked to hear when MoneySight 2.0 launches. Only the e-mail address is kept; it
 * counts once the address is confirmed (double opt-in), and unsubscribing deletes the row.
 *
 * @property int $id
 * @property string $email
 * @property string $token
 * @property string|null $source
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['email', 'token', 'source', 'confirmed_at'])]
class WaitlistSignup extends Model
{
    /** @use HasFactory<WaitlistSignupFactory> */
    use HasFactory, Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Unconfirmed sign-ups are deleted after 30 days, as the privacy notice says.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNull('confirmed_at')->where('created_at', '<', now()->subDays(30));
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}

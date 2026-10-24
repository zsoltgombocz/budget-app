<?php

namespace App\Services;

use App\Enums\Plan;
use App\Models\Admin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which plan a user has (2.0, S27). In order: a plan set on the account (by an admin, later by a
 * subscription) until its end; the one 14-day Plusz offer; the first 30 days (the trial, Alap);
 * otherwise Alap. Payment is not live yet, so after the trial nobody is locked out.
 */
final class Plans
{
    public const int TRIAL_DAYS = 30;

    public const int OFFER_DAYS = 14;

    public function current(User $user, ?CarbonImmutable $now = null): Plan
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $user->plan !== null && ($user->plan_until === null || ! $user->plan_until->endOfDay()->isPast()) => $user->plan,
            $user->plus_offer_until !== null && $user->plus_offer_until->greaterThan($now) => Plan::PlusTrial,
            $this->trialEndsAt($user)->greaterThan($now) => Plan::Trial,
            default => Plan::Basic,
        };
    }

    public function trialEndsAt(User $user): CarbonImmutable
    {
        return CarbonImmutable::parse($user->created_at ?? 'now')->addDays(self::TRIAL_DAYS);
    }

    /**
     * Set a plan on the account (admin): null goes back to automatic. Logged with who did it.
     */
    public function set(User $user, ?Plan $plan, ?CarbonImmutable $until = null, ?Admin $admin = null, ?string $note = null): void
    {
        if ($plan === Plan::Trial || $plan === Plan::PlusTrial) {
            throw ValidationException::withMessages(['plan' => __('Choose Alap or Plusz.')]);
        }

        DB::transaction(function () use ($user, $plan, $until, $admin, $note): void {
            $user->forceFill(['plan' => $plan, 'plan_until' => $plan instanceof Plan ? $until?->toDateString() : null])->save();
            $user->planChanges()->create(['admin_id' => $admin?->id, 'plan' => $plan, 'plan_until' => $plan instanceof Plan ? $until?->toDateString() : null, 'note' => $note]);
        });
    }

    /**
     * The one 14-day Plusz offer after the trial: no card, nothing is charged, it simply ends.
     */
    public function canStartOffer(User $user, ?CarbonImmutable $now = null): bool
    {
        return $user->plus_offer_until === null && $this->current($user, $now) === Plan::Basic;
    }

    public function startOffer(User $user, ?CarbonImmutable $now = null): CarbonImmutable
    {
        if (! $this->canStartOffer($user, $now)) {
            throw ValidationException::withMessages(['plan' => __('The Plusz trial can be started once, after the trial.')]);
        }

        $until = ($now ?? CarbonImmutable::now())->addDays(self::OFFER_DAYS);

        DB::transaction(function () use ($user, $until): void {
            $user->forceFill(['plus_offer_until' => $until])->save();
            $user->planChanges()->create(['plan' => Plan::PlusTrial, 'plan_until' => $until->toDateString(), 'note' => '14-day offer']);
        });

        return $until;
    }
}

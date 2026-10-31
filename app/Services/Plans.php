<?php

namespace App\Services;

use App\Enums\LineType;
use App\Enums\Plan;
use App\Models\Admin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription;

/**
 * Which plan a user has. Alap is free (salary, fixed items, budgets); Plusz adds pockets, loans and
 * putting money aside. In order: a plan set on the account by an admin, until its end; a paid Stripe
 * subscription (also on its grace period after cancelling); the first 14 days (Plusz trial); Alap.
 */
final class Plans
{
    public const int TRIAL_DAYS = 14;

    public const string SUBSCRIPTION = 'default';

    public function current(User $user, ?CarbonImmutable $now = null): Plan
    {
        $now ??= CarbonImmutable::now();

        $setPlan = $user->plan !== null && ($user->plan_until === null || ! $user->plan_until->endOfDay()->isPast()) ? $user->plan : null;

        // A paid subscription is never taken away by an admin setting Alap.
        return match (true) {
            $setPlan === Plan::Plus => Plan::Plus,
            $this->subscribed($user) => Plan::Plus,
            $setPlan === Plan::Basic => Plan::Basic,
            $this->trialEndsAt($user)->greaterThan($now) => Plan::PlusTrial,
            default => Plan::Basic,
        };
    }

    /**
     * A valid Stripe subscription: active, trialing, or cancelled but still on its paid time.
     */
    public function subscribed(User $user): bool
    {
        $subscription = $user->relationLoaded('subscriptions')
            ? $user->subscriptions->firstWhere('type', self::SUBSCRIPTION)
            : $user->subscriptions()->where('type', self::SUBSCRIPTION)->latest('id')->first();

        return $subscription instanceof Subscription && $subscription->valid();
    }

    /**
     * 14 days from signing up; users who were here before paid Plusz started got theirs from the release.
     */
    public function trialEndsAt(User $user): CarbonImmutable
    {
        $fromSignUp = CarbonImmutable::parse($user->created_at ?? 'now')->addDays(self::TRIAL_DAYS);

        return $user->plus_offer_until !== null && $user->plus_offer_until->greaterThan($fromSignUp) ? $user->plus_offer_until : $fromSignUp;
    }

    /**
     * Plan lines that only Plusz counts: money put aside into pockets. A transfer to another
     * account (e.g. the shared one) is a fixed outgoing payment and counts on Alap too.
     *
     * @return list<LineType>
     */
    public function lockedLineTypes(User $user): array
    {
        return $user->hasPlusz() ? [] : [LineType::Sinking];
    }

    /**
     * Set a plan on the account (admin): null goes back to automatic. Logged with who did it.
     */
    public function set(User $user, ?Plan $plan, ?CarbonImmutable $until = null, ?Admin $admin = null, ?string $note = null): void
    {
        if ($plan === Plan::PlusTrial) {
            throw ValidationException::withMessages(['plan' => __('Choose Alap or Plusz.')]);
        }

        DB::transaction(function () use ($user, $plan, $until, $admin, $note): void {
            $user->forceFill(['plan' => $plan, 'plan_until' => $plan instanceof Plan ? $until?->toDateString() : null])->save();
            $user->planChanges()->create(['admin_id' => $admin?->id, 'plan' => $plan, 'plan_until' => $plan instanceof Plan ? $until?->toDateString() : null, 'note' => $note]);
        });
    }
}

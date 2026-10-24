<?php

namespace App\Services;

use App\Enums\LoanEventType;
use App\Models\Achievement;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Data\Milestone;
use App\Services\Data\WeeklyChallenge;
use Carbon\CarbonImmutable;

/**
 * The small playful part: milestones and the weekly challenge. Everything is worked out from what
 * the user did (entries, no-spend days, closings, pockets, prepayments), so it can be synced at any
 * time and a milestone is never reached twice.
 */
final readonly class Rewards
{
    public function __construct(private Streak $streak) {}

    /**
     * Milestones in the order the streak sheet shows them.
     *
     * @return array<string, array{title: string, icon: string}>
     */
    public static function milestones(): array
    {
        return [
            'week' => ['title' => __('First week'), 'icon' => 'event_available'],
            'first_close' => ['title' => __('First closing'), 'icon' => 'check'],
            'under_budget' => ['title' => __('Closed within budget'), 'icon' => 'target'],
            'month' => ['title' => __('30-day streak'), 'icon' => 'local_fire_department'],
            'goal_reached' => ['title' => __('Goal reached'), 'icon' => 'shield'],
            'first_prepayment' => ['title' => __('First prepayment'), 'icon' => 'account_balance'],
        ];
    }

    /**
     * The weekly challenges, taking turns by week: what counts and how many days it needs.
     *
     * @return array<string, int>
     */
    public static function challengeKinds(): array
    {
        return ['no_spend' => 3, 'days' => 5];
    }

    /**
     * Record what is due and return the milestones reached just now.
     *
     * @return list<string>
     */
    public function sync(User $user, CarbonImmutable $today): array
    {
        $dates = $this->streak->activeDates($user, $today);
        $longest = $this->streak->state($dates, $today)->longest;

        $reached = [
            'week' => $longest >= 7,
            'first_close' => $user->periodCloses()->exists(),
            'under_budget' => $user->periodCloses()->where('leftover', '>=', 0)->exists(),
            'month' => $longest >= 30,
            'goal_reached' => $user->pockets()->withTrashed()->whereNotNull('target_amount')->where('target_amount', '>', 0)->whereColumn('balance', '>=', 'target_amount')->exists(),
            'first_prepayment' => $user->loanEvents()->where('type', LoanEventType::Prepayment)->exists(),
        ];

        $have = $user->achievements()->pluck('key')->all();
        $new = [];

        foreach (array_keys(self::milestones()) as $key) {
            if (! $reached[$key] || in_array($key, $have, true)) {
                continue;
            }

            $user->achievements()->create(['key' => $key, 'achieved_at' => now()]);
            $new[] = $key;
        }

        $challenge = $this->challenge($user, $today);

        if ($challenge->completed_at === null && $this->challengeCount($user, $challenge, $today) >= $challenge->target) {
            $challenge->update(['completed_at' => now()]);
        }

        return $new;
    }

    /**
     * Every milestone with when it was reached, or how far along it is.
     *
     * @return list<Milestone>
     */
    public function overview(User $user, CarbonImmutable $today): array
    {
        $achieved = $user->achievements()->get()->mapWithKeys(fn (Achievement $achievement): array => [$achievement->key => $achievement->achieved_at]);
        $state = $this->streak->stateForUser($user, $today);
        $goal = $user->pockets()->whereNotNull('target_amount')->where('target_amount', '>', 0)->orderByDesc('balance')->first();

        $progress = [
            'week' => min(100, intdiv($state->longest * 100, 7)),
            'month' => min(100, intdiv($state->longest * 100, 30)),
            'goal_reached' => $goal !== null && $goal->target_amount !== null ? min(100, intdiv(max(0, $goal->balance) * 100, $goal->target_amount)) : 0,
        ];

        $milestones = [];

        foreach (self::milestones() as $key => $milestone) {
            $at = $achieved[$key] ?? null;

            $milestones[] = new Milestone(
                key: $key,
                title: $milestone['title'],
                icon: $milestone['icon'],
                achievedAt: $at,
                progress: $progress[$key] ?? 0,
                detail: match ($key) {
                    'week' => min($state->longest, 7).' / 7',
                    'month' => min($state->longest, 30).' / 30',
                    'goal_reached' => $progress['goal_reached'].'%',
                    default => null,
                },
            );
        }

        return $milestones;
    }

    /**
     * This week's challenge; the kind takes turns by week.
     */
    public function challenge(User $user, CarbonImmutable $today): Challenge
    {
        $weekStart = $today->startOfWeek();
        $kinds = self::challengeKinds();
        $kind = array_keys($kinds)[$weekStart->weekOfYear % count($kinds)];

        return $user->challenges()->whereDate('week_start', $weekStart->toDateString())->first()
            ?? $user->challenges()->create(['week_start' => $weekStart->toDateString(), 'kind' => $kind, 'target' => $kinds[$kind], 'accepted_at' => now()]);
    }

    public function weeklyChallenge(User $user, CarbonImmutable $today): WeeklyChallenge
    {
        $challenge = $this->challenge($user, $today);

        return new WeeklyChallenge(
            kind: $challenge->kind,
            target: $challenge->target,
            done: min($challenge->target, $this->challengeCount($user, $challenge, $today)),
            completed: $challenge->completed_at !== null,
        );
    }

    /**
     * The milestones reached but not celebrated yet, marking them celebrated.
     *
     * @return list<string>
     */
    public function takeCelebrations(User $user): array
    {
        $keys = array_values($user->achievements()->whereNull('celebrated_at')->orderBy('id')->get()->map(fn (Achievement $achievement): string => $achievement->key)->all());
        $user->achievements()->whereNull('celebrated_at')->update(['celebrated_at' => now()]);

        return $keys;
    }

    private function challengeCount(User $user, Challenge $challenge, CarbonImmutable $today): int
    {
        $start = $challenge->week_start->toDateString();
        $end = $challenge->week_start->endOfWeek()->toDateString();

        if ($challenge->kind === 'no_spend') {
            return $user->dayMarks()->whereDate('date', '>=', $start)->whereDate('date', '<=', $end)->count();
        }

        return count(array_filter($this->streak->activeDates($user, $today, 7), fn (string $date): bool => $date >= $start && $date <= $end));
    }
}

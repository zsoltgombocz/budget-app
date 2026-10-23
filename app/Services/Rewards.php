<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\Challenge;
use App\Models\User;
use App\Support\Wardrobe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The playful part: acorns for the habit, milestones, the weekly challenge and Morzsa's things.
 * Everything is worked out from what the user did (entries, no-spend days, closings, goals), so it
 * can be synced at any time and an award never happens twice. Acorns are never sold for money.
 */
final readonly class Rewards
{
    public const int PER_DAY = 5;

    public const int CHALLENGE = 20;

    public const int CHALLENGE_DAYS = 5;

    public function __construct(private Streak $streak) {}

    /**
     * Milestones in the order the screen shows them, with their acorns.
     *
     * @return array<string, array{title: string, text: string, acorns: int}>
     */
    public static function milestones(): array
    {
        return [
            'first_entry' => ['title' => __('First step'), 'text' => __('The first entry'), 'acorns' => 10],
            'week' => ['title' => __('A week'), 'text' => __('7 days in a row'), 'acorns' => 30],
            'month' => ['title' => __('A habit'), 'text' => __('30 days in a row'), 'acorns' => 60],
            'first_close' => ['title' => __('First month'), 'text' => __('The first closing'), 'acorns' => 20],
            'came_out' => ['title' => __('We came out'), 'text' => __('A month that did not end in the minus'), 'acorns' => 20],
            'goal_reached' => ['title' => __('Goal reached'), 'text' => __('A goal reached'), 'acorns' => 50],
            'quarter' => ['title' => __('A quarter'), 'text' => __('3 closed months'), 'acorns' => 40],
            'year' => ['title' => __('A year'), 'text' => __('365 days of use'), 'acorns' => 100],
        ];
    }

    /**
     * Award what is due and return the milestones reached just now.
     *
     * @return list<string>
     */
    public function sync(User $user, CarbonImmutable $today): array
    {
        $dates = $this->streak->activeDates($user, $today);
        $state = $this->streak->state($dates, $today);

        $rows = array_map(fn (string $date): array => ['user_id' => $user->id, 'amount' => self::PER_DAY, 'reason' => 'day', 'ref' => $date, 'created_at' => now(), 'updated_at' => now()], $dates);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('acorn_events')->insertOrIgnore($chunk);
        }

        $closes = $user->periodCloses()->count();
        $reached = [
            'first_entry' => $dates !== [],
            'week' => $state->longest >= 7,
            'month' => $state->longest >= 30,
            'first_close' => $closes >= 1,
            'came_out' => $user->periodCloses()->where('leftover', '>=', 0)->exists(),
            'goal_reached' => $user->pockets()->withTrashed()->whereNotNull('target_amount')->whereColumn('balance', '>=', 'target_amount')->exists(),
            'quarter' => $closes >= 3,
            'year' => count($dates) >= 365,
        ];

        $have = $user->achievements()->pluck('key')->all();
        $new = [];

        foreach (self::milestones() as $key => $milestone) {
            if (! $reached[$key] || in_array($key, $have, true)) {
                continue;
            }

            $user->achievements()->create(['key' => $key, 'achieved_at' => now()]);
            $this->award($user, $milestone['acorns'], 'milestone', $key);
            $new[] = $key;
        }

        $challenge = $user->challenges()->whereDate('week_start', $today->startOfWeek()->toDateString())->first();

        if ($challenge instanceof Challenge && $challenge->accepted_at !== null && $challenge->completed_at === null && $this->daysThisWeek($dates, $today) >= $challenge->target) {
            $challenge->update(['completed_at' => now()]);
            $this->award($user, self::CHALLENGE, 'challenge', $challenge->week_start->toDateString());
        }

        return $new;
    }

    public function balance(User $user): int
    {
        return (int) $user->acornEvents()->sum('amount');
    }

    /**
     * This week's challenge: offered until it is taken or declined.
     */
    public function challenge(User $user, CarbonImmutable $today): Challenge
    {
        $weekStart = $today->startOfWeek()->toDateString();

        return $user->challenges()->whereDate('week_start', $weekStart)->first()
            ?? $user->challenges()->create(['week_start' => $weekStart, 'kind' => 'days', 'target' => self::CHALLENGE_DAYS]);
    }

    /**
     * @param  list<string>  $dates
     */
    public function daysThisWeek(array $dates, CarbonImmutable $today): int
    {
        $start = $today->startOfWeek()->toDateString();
        $end = $today->endOfWeek()->toDateString();

        return count(array_filter($dates, fn (string $date): bool => $date >= $start && $date <= $end));
    }

    public function buy(User $user, string $key): void
    {
        $item = Wardrobe::find($key);

        if ($item === null) {
            throw ValidationException::withMessages(['item' => __('There is no such thing.')]);
        }

        if ($user->items()->where('item', $key)->exists()) {
            return;
        }

        if ($this->balance($user) < $item['price']) {
            throw ValidationException::withMessages(['item' => __('Not enough acorns yet.')]);
        }

        DB::transaction(function () use ($user, $key, $item): void {
            $this->award($user, -$item['price'], 'item', $key);
            $this->unequipSlot($user, $item['slot']);
            $user->items()->create(['item' => $key, 'equipped' => true]);
        });
    }

    public function toggle(User $user, string $key): void
    {
        $owned = $user->items()->where('item', $key)->firstOrFail();
        $item = Wardrobe::find($key);

        if (! $owned->equipped && $item !== null) {
            $this->unequipSlot($user, $item['slot']);
        }

        $owned->update(['equipped' => ! $owned->equipped]);
    }

    /**
     * The milestones reached but not celebrated yet, marking them celebrated.
     *
     * @return list<string>
     */
    public function takeCelebrations(User $user): array
    {
        $keys = $user->achievements()->whereNull('celebrated_at')->orderBy('id')->get()->map(fn (Achievement $achievement): string => $achievement->key)->all();
        $keys = array_values($keys);
        $user->achievements()->whereNull('celebrated_at')->update(['celebrated_at' => now()]);

        return $keys;
    }

    private function award(User $user, int $amount, string $reason, string $ref): void
    {
        DB::table('acorn_events')->insertOrIgnore(['user_id' => $user->id, 'amount' => $amount, 'reason' => $reason, 'ref' => $ref, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function unequipSlot(User $user, string $slot): void
    {
        $keys = array_keys(array_filter(Wardrobe::items(), fn (array $item): bool => $item['slot'] === $slot));
        $user->items()->whereIn('item', $keys)->update(['equipped' => false]);
    }
}

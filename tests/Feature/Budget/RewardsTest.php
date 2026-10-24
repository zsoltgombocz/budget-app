<?php

use App\Enums\LoanEventType;
use App\Enums\PeriodStatus;
use App\Models\Category;
use App\Models\DayMark;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Rewards;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->period = Period::factory()->for($this->user)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    $this->category = Category::factory()->for($this->user)->create();
    $this->today = CarbonImmutable::parse('2026-10-15');
});

function writtenOn(object $test, string ...$dates): void
{
    foreach ($dates as $date) {
        Transaction::factory()->for($test->user)->for($test->period)->for($test->category)->create(['occurred_on' => $date]);
    }
}

it('reaches milestones once and lets them be celebrated once', function (): void {
    writtenOn($this, ...array_map(fn (int $day): string => sprintf('2026-10-%02d', $day), range(9, 15)));
    $closed = Period::factory()->for($this->user)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => PeriodStatus::Closed]);
    PeriodClose::factory()->for($this->user)->for($closed)->create(['leftover' => 18_400]);
    $rewards = resolve(Rewards::class);

    expect($rewards->sync($this->user, $this->today))->toBe(['week', 'first_close', 'under_budget'])
        ->and($rewards->sync($this->user, $this->today))->toBe([])
        ->and($rewards->takeCelebrations($this->user))->toBe(['week', 'first_close', 'under_budget'])
        ->and($rewards->takeCelebrations($this->user))->toBe([]);
});

it('does not count a closing in the minus as within budget', function (): void {
    $closed = Period::factory()->for($this->user)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => PeriodStatus::Closed]);
    PeriodClose::factory()->for($this->user)->for($closed)->create(['leftover' => -5_000]);

    expect(resolve(Rewards::class)->sync($this->user, $this->today))->toBe(['first_close']);
});

it('marks a goal reached and the first prepayment', function (): void {
    Pocket::factory()->for($this->user)->create(['target_amount' => 300_000, 'balance' => 300_000]);
    LoanEvent::factory()->for($this->user)->for(Loan::factory()->for($this->user))->create(['type' => LoanEventType::Prepayment]);

    expect(resolve(Rewards::class)->sync($this->user, $this->today))->toBe(['goal_reached', 'first_prepayment']);
});

it('shows how far the milestones not reached yet are', function (): void {
    writtenOn($this, '2026-10-13', '2026-10-14', '2026-10-15');
    Pocket::factory()->for($this->user)->create(['target_amount' => 300_000, 'balance' => 204_522]);

    $milestones = collect(resolve(Rewards::class)->overview($this->user, $this->today))->keyBy('key');

    expect($milestones['week']->detail)->toBe('3 / 7')
        ->and($milestones['month']->detail)->toBe('3 / 30')
        ->and($milestones['goal_reached']->detail)->toBe('68%')
        ->and($milestones['first_close']->achievedAt)->toBeNull();
});

it('completes the no-spend challenge at three marked days', function (): void {
    $rewards = resolve(Rewards::class);
    $monday = CarbonImmutable::parse('2026-10-12');
    $this->user->challenges()->create(['week_start' => '2026-10-12', 'kind' => 'no_spend', 'target' => 3, 'accepted_at' => now()]);

    foreach (['2026-10-12', '2026-10-13'] as $date) {
        DayMark::factory()->for($this->user)->create(['date' => $date]);
    }

    expect($rewards->weeklyChallenge($this->user, $monday->addDays(2))->done)->toBe(2);

    DayMark::factory()->for($this->user)->create(['date' => '2026-10-14']);
    $rewards->sync($this->user, $monday->addDays(2));

    expect($rewards->weeklyChallenge($this->user, $monday->addDays(2))->completed)->toBeTrue();
});

it('completes the days challenge at five days written in', function (): void {
    $rewards = resolve(Rewards::class);
    $this->user->challenges()->create(['week_start' => '2026-10-12', 'kind' => 'days', 'target' => 5, 'accepted_at' => now()]);
    writtenOn($this, '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16');

    $rewards->sync($this->user, CarbonImmutable::parse('2026-10-16'));

    expect($rewards->weeklyChallenge($this->user, CarbonImmutable::parse('2026-10-16'))->completed)->toBeTrue();
});

it('offers a new challenge each week, taking turns', function (): void {
    $rewards = resolve(Rewards::class);

    $first = $rewards->challenge($this->user, CarbonImmutable::parse('2026-10-15'));
    $next = $rewards->challenge($this->user, CarbonImmutable::parse('2026-10-22'));

    expect($first->kind)->not->toBe($next->kind)
        ->and($rewards->challenge($this->user, CarbonImmutable::parse('2026-10-16'))->id)->toBe($first->id);
});

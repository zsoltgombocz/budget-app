<?php

use App\Enums\PeriodStatus;
use App\Models\Category;
use App\Models\DayMark;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Rewards;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->period = Period::factory()->for($this->user)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    $this->category = Category::factory()->for($this->user)->create();
    $this->today = CarbonImmutable::parse('2026-10-15');
});

function activeOn(object $test, string ...$dates): void
{
    foreach ($dates as $date) {
        Transaction::factory()->for($test->user)->for($test->period)->for($test->category)->create(['occurred_on' => $date]);
    }
}

it('gives acorns for each day written in, once', function (): void {
    activeOn($this, '2026-10-14', '2026-10-15', '2026-10-15');
    DayMark::factory()->for($this->user)->create(['date' => '2026-10-13']);
    $rewards = resolve(Rewards::class);

    $rewards->sync($this->user, $this->today);
    $rewards->sync($this->user, $this->today);

    // 3 days × 5, plus the first-step milestone (10).
    expect($rewards->balance($this->user))->toBe(3 * Rewards::PER_DAY + 10);
});

it('reaches milestones once and lets them be celebrated once', function (): void {
    activeOn($this, ...array_map(fn (int $day): string => sprintf('2026-10-%02d', $day), range(9, 15)));
    $closed = Period::factory()->for($this->user)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => PeriodStatus::Closed]);
    PeriodClose::factory()->for($this->user)->for($closed)->create(['leftover' => 18_400]);
    $rewards = resolve(Rewards::class);

    expect($rewards->sync($this->user, $this->today))->toBe(['first_entry', 'week', 'first_close', 'came_out'])
        ->and($rewards->sync($this->user, $this->today))->toBe([])
        ->and($rewards->takeCelebrations($this->user))->toBe(['first_entry', 'week', 'first_close', 'came_out'])
        ->and($rewards->takeCelebrations($this->user))->toBe([]);
});

it('marks a goal reached', function (): void {
    Pocket::factory()->for($this->user)->create(['target_amount' => 300_000, 'balance' => 300_000]);

    expect(resolve(Rewards::class)->sync($this->user, $this->today))->toContain('goal_reached');
});

it('completes a taken weekly challenge at five days', function (): void {
    $rewards = resolve(Rewards::class);
    $challenge = $rewards->challenge($this->user, CarbonImmutable::parse('2026-10-16'));
    $challenge->update(['accepted_at' => now()]);
    activeOn($this, '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16');

    $rewards->sync($this->user, CarbonImmutable::parse('2026-10-16'));

    expect($challenge->fresh()->completed_at)->not->toBeNull()
        ->and($this->user->acornEvents()->where('reason', 'challenge')->sum('amount'))->toBe(Rewards::CHALLENGE);
});

it('buys a thing for acorns, wears it and takes it off', function (): void {
    $this->user->acornEvents()->create(['amount' => 50, 'reason' => 'test', 'ref' => 'x']);
    $rewards = resolve(Rewards::class);

    $rewards->buy($this->user, 'glasses');

    expect($rewards->balance($this->user))->toBe(10)
        ->and($this->user->items()->sole()->equipped)->toBeTrue();

    $rewards->toggle($this->user, 'glasses');

    expect($this->user->items()->sole()->equipped)->toBeFalse();
});

it('does not sell a thing without enough acorns', function (): void {
    resolve(Rewards::class)->buy($this->user, 'shelf');
})->throws(ValidationException::class);

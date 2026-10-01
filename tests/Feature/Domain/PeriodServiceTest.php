<?php

use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\User;
use App\Services\PeriodService;
use App\Services\PlanService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create([
        'period_mode' => PeriodMode::Payday,
        'payday_day' => 10,
        'income' => 500_000,
    ]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Rent', 'type' => LineType::Fixed]);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 200_000]);
});

it('opens a period with a snapshot of the plan', function (): void {
    $period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-01'));

    expect($period->starts_on->toDateString())->toBe('2026-09-10')
        ->and($period->ends_on->toDateString())->toBe('2026-10-09')
        ->and($period->income_planned)->toBe(500_000)
        ->and($period->status)->toBe(PeriodStatus::Open)
        ->and($period->plan_snapshot)->toHaveCount(1)
        ->and($period->plan_snapshot[0]['planned'])->toBe(200_000);
});

it('reuses the existing period for dates inside it', function (): void {
    $service = resolve(PeriodService::class);

    $first = $service->forDate($this->user, CarbonImmutable::parse('2026-09-15'));
    $second = $service->forDate($this->user, CarbonImmutable::parse('2026-10-09'));

    expect($second->id)->toBe($first->id)
        ->and($this->user->periods()->count())->toBe(1);
});

it('uses the live plan while open and the snapshot once closed', function (): void {
    $period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-01'));
    BudgetLine::query()->update(['amount' => 250_000]);

    expect(resolve(PlanService::class)->summaryFor($period->refresh())->leftover)->toBe(250_000);

    $period->update(['status' => PeriodStatus::Closed]);

    expect(resolve(PlanService::class)->summaryFor($period->refresh())->leftover)->toBe(300_000);
});

it('skips lines outside their active window', function (): void {
    $category = Category::factory()->for($this->user)->create(['type' => LineType::Fixed]);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 9_000, 'active_to' => '2026-08-31']);

    $period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-01'));

    expect(resolve(PlanService::class)->summaryFor($period)->totalExpenses)->toBe(200_000);
});

it('creates default settings on first access', function (): void {
    $settings = User::factory()->create()->settings();

    expect($settings->period_mode)->toBe(PeriodMode::Calendar)
        ->and($settings->reserve_pct)->toBe(100)
        ->and($settings->isOnboarded())->toBeFalse();
});

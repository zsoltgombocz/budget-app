<?php

use App\Enums\LineType;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Services\PeriodService;
use App\Services\PlanChangeImpact;

beforeEach(function (): void {
    $this->travelTo('2026-10-15 12:00:00');
    $this->user = onboardedUser();
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->impact = resolve(PlanChangeImpact::class);
    $this->fuel = BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->firstOrFail();
    $this->rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();
});

it('applies a plan change to the open period from its first day', function (): void {
    expect($this->impact->appliesFrom($this->user)?->toDateString())->toBe('2026-10-01')
        ->and($this->impact->appliesFrom($this->user, $this->fuel)?->toDateString())->toBe('2026-10-01');
});

it('does not reach a period the line is not active in', function (): void {
    $this->fuel->update(['active_from' => '2026-11-01']);

    expect($this->impact->appliesFrom($this->user, $this->fuel->refresh()))->toBeNull();
});

it('leaves a closed period alone: it keeps its frozen snapshot', function (): void {
    $this->period->update(['status' => PeriodStatus::Closed]);

    expect($this->impact->appliesFrom($this->user))->toBeNull();
});

it('reports the spending a switch between recorded and planned types would recount', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel->category)->create(['amount' => 12_000]);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel->category)->create(['amount' => 3_000]);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->rent->category)->create(['amount' => 200_000]);

    expect($this->impact->spendingRecountedBy($this->user, $this->fuel, LineType::Fixed))->toBe(15_000)
        ->and($this->impact->spendingRecountedBy($this->user, $this->rent, LineType::Variable))->toBe(200_000);
});

it('needs no warning when the spending is counted the same way afterwards', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->rent->category)->create(['amount' => 200_000]);

    // Fixed and sinking both count with the planned amount; variable stays variable.
    expect($this->impact->spendingRecountedBy($this->user, $this->rent, LineType::Sinking))->toBeNull()
        ->and($this->impact->spendingRecountedBy($this->user, $this->fuel, LineType::Variable))->toBeNull();
});

it('needs no warning without spending that uses the budget in the open period', function (): void {
    expect($this->impact->spendingRecountedBy($this->user, $this->fuel, LineType::Fixed))->toBeNull();

    // Paid from a pocket: it never used the budget.
    $pocket = Pocket::factory()->for($this->user)->create();
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel->category)->create(['amount' => 5_000, 'pocket_id' => $pocket->id]);
    expect($this->impact->spendingRecountedBy($this->user, $this->fuel, LineType::Fixed))->toBeNull();

    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel->category)->create(['amount' => 5_000]);
    $this->period->update(['status' => PeriodStatus::Closed]);
    expect($this->impact->spendingRecountedBy($this->user, $this->fuel, LineType::Fixed))->toBeNull();
});

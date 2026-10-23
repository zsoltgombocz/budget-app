<?php

use App\Actions\Budget\ChangeFixedItemStatus;
use App\Actions\Budget\SetLineStatus;
use App\Enums\LineStatus;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Services\OverviewService;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->period = resolve(PeriodService::class)->current($this->user);
    $category = Category::factory()->for($this->user)->create(['name' => 'PS Plus', 'type' => 'fixed']);
    $this->psPlus = BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 4_990, 'due_day' => 3]);
});

function psPlusStatus(): LineStatus
{
    $item = collect(resolve(OverviewService::class)->forUser(test()->user)->fixedItems)->first(fn ($item): bool => $item->line->categoryName === 'PS Plus');

    return $item->status();
}

it('does not remind about a skipped item that is due today', function (): void {
    $this->psPlus->update(['due_day' => 11]);
    resolve(SetLineStatus::class)->handle($this->user, $this->period, $this->psPlus->id, LineStatus::Skipped);

    $items = resolve(OverviewService::class)->forUser($this->user)->fixedItems;
    $psPlus = collect($items)->first(fn ($item): bool => $item->line->categoryName === 'PS Plus');

    expect($psPlus->isOverdue(CarbonImmutable::parse('2026-10-11')))->toBeFalse()
        ->and($psPlus->isSkipped())->toBeTrue()
        ->and(end($items)->line->categoryName)->toBe('PS Plus');
});

it('changes nothing in a closed period', function (): void {
    $action = resolve(ChangeFixedItemStatus::class);
    $items = resolve(OverviewService::class)->forUser($this->user)->fixedItems;

    expect(fn () => $action->toggle($this->user, $this->period, $items, 999_999))->toThrow(ModelNotFoundException::class);

    $this->period->update(['status' => PeriodStatus::Closed]);

    expect($action->set($this->user, $this->period, $items, $this->psPlus->id, LineStatus::Skipped))->toBeNull()
        ->and($action->undo($this->user, $this->period, $items, $this->psPlus->id, 'paid'))->toBeFalse()
        ->and($this->period->lineStatuses()->count())->toBe(0);
});

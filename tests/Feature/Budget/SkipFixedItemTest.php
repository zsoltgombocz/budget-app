<?php

use App\Actions\Budget\ChangeFixedItemStatus;
use App\Actions\Budget\SetLineStatus;
use App\Enums\LineStatus;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Services\OverviewService;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

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

it('shows the skipped item on the Month list and leaves it out of the fixed total', function (): void {
    $month = Livewire::test('pages::month')
        ->call('skipLine', $this->psPlus->id)
        ->assertDispatched('app-toast', undo: 'fixed-item-undo')
        ->assertSee('data-test="fixed-skipped"', false)
        ->assertSee(__(':count skipped', ['count' => 1]))
        ->assertSeeHtmlInOrder(['data-test="fixed-list"', 'data-status="skipped"', 'PS Plus', __('skipped – counted as :zero', ['zero' => money(0)])]);

    // The payday row adds up what the fixed items take: rent only.
    expect($month->html())->toContain(money(200_000));

    $month->dispatch('fixed-item-undo', budgetLineId: $this->psPlus->id, status: 'open');
    expect(psPlusStatus())->toBe(LineStatus::Open);
});

it('closes with the skipped item as planned against 0 and a bigger leftover', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00', 'Europe/Budapest'));
    $closer = resolve(PeriodCloser::class);
    $before = $closer->preview($this->user, $this->period)->leftover();

    resolve(SetLineStatus::class)->handle($this->user, $this->period, $this->psPlus->id, LineStatus::Skipped);

    $preview = $closer->preview($this->user, $this->period);
    $row = collect($preview->categories)->firstWhere('name', 'PS Plus');
    expect($row)->toMatchArray(['planned' => 4_990, 'actual' => 0, 'diff' => -4_990, 'skipped' => true])
        ->and($preview->leftover())->toBe($before + 4_990);

    $this->get(route('close', $this->period))->assertOk()->assertSeeInOrder(['PS Plus', 'data-test="plan-actual-note"', __('skipped')], false);

    $close = $closer->close($this->user, $this->period);
    expect($close->breakdown['categories'])->toContain([...$row]);

    Livewire::test('pages::month', ['periodId' => $this->period->id])
        ->assertSeeHtmlInOrder(['data-test="closed-breakdown"', 'data-test="skipped-row"', 'PS Plus', 'not paid']);
});

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

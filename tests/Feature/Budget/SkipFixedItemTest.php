<?php

use App\Actions\Budget\ChangeFixedItemStatus;
use App\Actions\Budget\SetLineStatus;
use App\Enums\LineStatus;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Pocket;
use App\Services\OverviewService;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
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

it('skips an overdue fixed item on Today: off the due cards, shown in the list, counted as 0', function (): void {
    $before = Livewire::test('pages::today')->instance();
    $leftover = $before->currentLeftover;
    $ifSpent = $before->overview->forecast->expectedLeftover;

    Livewire::test('pages::today')
        ->assertSeeHtmlInOrder(['data-test="due-item"', 'PS Plus', 'data-test="skip-due"', __('Skipped this month')])
        ->call('skipLine', $this->psPlus->id)
        ->assertDispatched('budget-updated')
        ->assertDispatched('app-toast',
            title: __('Skipped this month.'),
            subtitle: __(':amount more is left.', ['amount' => money(4_990)]),
            undo: 'fixed-item-undo',
            params: ['budgetLineId' => $this->psPlus->id, 'status' => 'open'],
        )
        ->assertDontSee('data-test="due-item"', false)
        ->assertSeeHtmlInOrder(['data-test="fixed-items"', 'data-status="skipped"', 'PS Plus', __('skipped – counted as :zero', ['zero' => money(0)]), 'line-through']);

    $after = Livewire::test('pages::today')->instance();
    expect(psPlusStatus())->toBe(LineStatus::Skipped)
        ->and($after->currentLeftover)->toBe($leftover + 4_990)
        ->and($after->overview->forecast->expectedLeftover)->toBe($ifSpent + 4_990);
});

it('undoes a skip back to the status it had', function (): void {
    $today = Livewire::test('pages::today')->call('skipLine', $this->psPlus->id);
    $today->dispatch('fixed-item-undo', budgetLineId: $this->psPlus->id, status: 'open');
    expect(psPlusStatus())->toBe(LineStatus::Open);
});

it('does not skip an item that was ticked off, nor offer it', function (): void {
    $today = Livewire::test('pages::today')->call('togglePaid', $this->psPlus->id);

    $today->call('skipLine', $this->psPlus->id)->assertNotDispatched('app-toast', title: __('Skipped this month.'));
    expect(psPlusStatus())->toBe(LineStatus::Paid);

    // Rent (open) still offers it; the ticked-off PS Plus does not.
    expect(substr_count(Livewire::test('pages::today')->html(), 'data-test="fixed-item-options"'))->toBe(1);
});

it('lets a skipped item come up after all, then it can be ticked off as usual', function (): void {
    $today = Livewire::test('pages::today')->call('skipLine', $this->psPlus->id);

    $today->call('unskipLine', $this->psPlus->id)
        ->assertDispatched('app-toast', params: ['budgetLineId' => $this->psPlus->id, 'status' => 'skipped'], title: __('It comes up after all.'))
        ->assertSee('data-test="due-item"', false);
    expect(psPlusStatus())->toBe(LineStatus::Open);

    $today->call('togglePaid', $this->psPlus->id)->assertDispatched('app-toast', title: __('Paid.'));
    expect(psPlusStatus())->toBe(LineStatus::Paid);
});

it('offers skipping only for a plain fixed item', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Holiday']);
    $category = Category::factory()->for($this->user)->create(['name' => 'Holiday fund', 'type' => 'sinking']);
    $holiday = BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 20_000, 'due_day' => 2, 'pocket_id' => $pocket->id]);
    $fuel = BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->firstOrFail();

    $html = Livewire::test('pages::today')->html();
    expect(substr_count($html, 'data-test="skip-due"'))->toBe(1)
        ->and(substr_count($html, 'data-test="fixed-item-options"'))->toBe(2);

    expect(fn () => resolve(SetLineStatus::class)->handle($this->user, $this->period, $holiday->id, LineStatus::Skipped))->toThrow(ValidationException::class)
        ->and(fn () => resolve(SetLineStatus::class)->handle($this->user, $this->period, $fuel->id, LineStatus::Skipped))->toThrow(ValidationException::class);

    Livewire::test('pages::today')->call('skipLine', $holiday->id)->assertNotDispatched('app-toast');
});

it('cannot mark another user\'s line', function (): void {
    $foreign = BudgetLine::factory()->create();

    Livewire::test('pages::today')->call('skipLine', $foreign->id)->assertNotFound();
    expect(fn () => resolve(SetLineStatus::class)->handle($this->user, $this->period, $foreign->id, LineStatus::Skipped))
        ->toThrow(ModelNotFoundException::class);
});

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

    $this->get(route('close', $this->period))->assertOk()->assertSeeInOrder(['PS Plus', 'data-test="skipped-row"', __('skipped')], false);

    $close = $closer->close($this->user, $this->period);
    expect($close->breakdown['categories'])->toContain([...$row]);

    Livewire::test('pages::month', ['periodId' => $this->period->id])
        ->assertSeeHtmlInOrder(['data-test="closed-breakdown"', 'PS Plus', 'data-test="skipped-row"']);
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

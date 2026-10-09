<?php

use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\RecordTransaction;
use App\Actions\Budget\ReopenPeriod;
use App\Actions\Budget\ToggleLinePaid;
use App\Enums\PeriodStatus;
use App\Enums\PrepayMode;
use App\Models\Category;
use App\Models\CurrencyConversion;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\PocketMovement;
use App\Models\Transaction;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->groceries = Category::query()->where('name', 'Groceries')->firstOrFail();
    $this->reserve = Pocket::factory()->for($this->user)->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 80_000, 'target_amount' => 100_000]);
    $this->savings = Pocket::factory()->for($this->user)->create(['name' => 'Savings']);
    $this->user->settings()->update(['surplus_pocket_id' => $this->savings->id, 'reserve_pct' => 100]);

    // 500 000 income − 200 000 rent − 10 000 fuel = 290 000 leftover: 20 000 fills the reserve, 270 000 to Savings.
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 10_000, 'occurred_on' => '2026-10-05']);
});

function closeEarly(): PeriodClose
{
    return resolve(PeriodCloser::class)->close(test()->user, test()->period);
}

function expectRefusal(string $message): void
{
    expect(fn () => resolve(ReopenPeriod::class)->handle(test()->user, test()->period->refresh()))
        ->toThrow(ValidationException::class, $message);
}

it('undoes an early closing: pockets, end date and the entries recorded since', function (): void {
    closeEarly();
    $next = resolve(PeriodService::class)->current($this->user);
    $holiday = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 5_000]);
    $later = resolve(RecordTransaction::class)->handle($this->user, $this->groceries->id, 5_000);
    $deposit = resolve(MovePocketMoney::class)->handle($this->user, $holiday, 1_000);

    expect($this->reserve->refresh()->balance)->toBe(100_000)
        ->and($this->savings->refresh()->balance)->toBe(270_000)
        ->and($later->period_id)->toBe($next->id);

    $reopened = resolve(ReopenPeriod::class)->handle($this->user, $this->period->refresh());

    expect($reopened->status)->toBe(PeriodStatus::Open)
        ->and($reopened->ends_on->toDateString())->toBe('2026-10-31')
        ->and($reopened->income_actual)->toBeNull()
        ->and($this->reserve->refresh()->balance)->toBe(80_000)
        ->and($this->savings->refresh()->balance)->toBe(0)
        ->and($holiday->refresh()->balance)->toBe(6_000)
        ->and($later->refresh()->period_id)->toBe($this->period->id)
        ->and($deposit->refresh()->period_id)->toBe($this->period->id)
        ->and(Period::query()->whereKey($next->id)->exists())->toBeFalse()
        ->and(PeriodClose::query()->count())->toBe(0)
        ->and(PocketMovement::query()->whereIn('pocket_id', [$this->reserve->id, $this->savings->id])->count())->toBe(0)
        ->and(resolve(PeriodService::class)->current($this->user)->id)->toBe($this->period->id);
});

it('can close the month again after undoing the closing', function (): void {
    closeEarly();
    resolve(ReopenPeriod::class)->handle($this->user, $this->period->refresh());

    resolve(PeriodCloser::class)->close($this->user, $this->period->refresh());

    expect($this->reserve->refresh()->balance)->toBe(100_000)
        ->and($this->savings->refresh()->balance)->toBe(270_000)
        ->and($this->user->periods()->count())->toBe(2);
});

it('gives back a deficit taken from the reserve', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 350_000, 'occurred_on' => '2026-10-06']);
    closeEarly();

    expect($this->reserve->refresh()->balance)->toBe(20_000);

    resolve(ReopenPeriod::class)->handle($this->user, $this->period->refresh());

    expect($this->reserve->refresh()->balance)->toBe(80_000);
});

it('deletes the savings pocket the closing created, unless something else uses it', function (bool $keepAsDefault): void {
    $this->user->settings()->update(['surplus_pocket_id' => null]);
    $this->savings->forceDelete();
    resolve(PeriodCloser::class)->close($this->user, $this->period, null, null, 'new-pocket');
    $created = Pocket::query()->where('name', 'Savings')->sole();

    if ($keepAsDefault) {
        $this->user->settings()->update(['surplus_pocket_id' => $created->id]);
    }

    $preview = resolve(ReopenPeriod::class)->preview($this->user, $this->period->refresh());
    resolve(ReopenPeriod::class)->handle($this->user, $this->period);

    expect($preview->removedPocketId)->toBe($keepAsDefault ? null : $created->id)
        ->and(Pocket::query()->withTrashed()->whereKey($created->id)->exists())->toBe($keepAsDefault)
        ->and(Pocket::query()->withTrashed()->whereKey($created->id)->value('balance'))->toBe($keepAsDefault ? 0 : null);
})->with(['created only by the closing' => false, 'kept as the leftover target' => true]);

it('undoes a closing on the last day and removes the month opened for tomorrow', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00', 'Europe/Budapest'));
    closeEarly();
    $next = $this->user->periods()->whereDate('starts_on', '2026-11-01')->sole();

    resolve(ReopenPeriod::class)->handle($this->user, $this->period->refresh());

    expect($this->period->refresh()->isOpen())->toBeTrue()
        ->and($this->period->ends_on->toDateString())->toBe('2026-10-31')
        ->and(Period::query()->whereKey($next->id)->exists())->toBeFalse();
});

it('keeps the next month, starting after the regular end, when it holds later entries', function (): void {
    closeEarly();
    $next = resolve(PeriodService::class)->current($this->user);
    $future = Transaction::factory()->for($this->user)->for($next)->for($this->groceries)->create(['amount' => 3_000, 'occurred_on' => '2026-11-03']);

    resolve(ReopenPeriod::class)->handle($this->user, $this->period->refresh());

    expect($next->refresh()->starts_on->toDateString())->toBe('2026-11-01')
        ->and($future->refresh()->period_id)->toBe($next->id);
});

it('undoes a closing made before the undo data was stored', function (): void {
    $close = closeEarly();
    $breakdown = $close->breakdown;
    unset($breakdown['undo']);
    $close->update(['breakdown' => $breakdown]);

    resolve(ReopenPeriod::class)->handle($this->user, $this->period->refresh());

    expect($this->period->refresh()->ends_on->toDateString())->toBe('2026-10-31')
        ->and($this->reserve->refresh()->balance)->toBe(80_000)
        ->and($this->savings->refresh()->balance)->toBe(0);
});

it('refuses a legacy closing whose movements do not add up', function (): void {
    $close = closeEarly();
    $breakdown = $close->breakdown;
    unset($breakdown['undo']);
    $close->update(['breakdown' => $breakdown, 'to_reserve' => 25_000]);

    expectRefusal('could not be identified');
});

it('refuses when the period is not the latest closed one', function (): void {
    closeEarly();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Europe/Budapest'));
    resolve(PeriodCloser::class)->close($this->user, resolve(PeriodService::class)->current($this->user));

    expect(resolve(ReopenPeriod::class)->isOffered($this->user, $this->period->refresh()))->toBeFalse();
    expectRefusal('Only the most recent closing can be undone.');
});

it('refuses after the month\'s regular end', function (): void {
    closeEarly();
    $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Europe/Budapest'));

    expect(resolve(ReopenPeriod::class)->isOffered($this->user, $this->period->refresh()))->toBeFalse();
    expectRefusal('until the month’s regular end');
});

it('refuses an open period', function (): void {
    expectRefusal('This period is not closed.');
});

it('refuses once the leftover is marked as transferred', function (): void {
    $broker = $this->user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);
    $close = resolve(PeriodCloser::class)->close($this->user, $this->period, null, null, 'account:'.$broker->id);
    $close->update(['surplus_transferred_at' => now()]);

    expectRefusal('already marked as transferred');
});

it('refuses after a loan prepayment recorded since the closing', function (): void {
    closeEarly();
    $loan = $this->user->loans()->create(['name' => 'Car', 'principal_balance' => 1_000_000, 'installment' => 30_000, 'prepay_mode' => PrepayMode::ReduceInstallment]);
    resolve(RecordPrepayment::class)->handle($this->user, $loan, 100_000);

    expectRefusal('loan prepayment');
});

it('refuses when money moved in a pocket the closing touched', function (): void {
    closeEarly();
    resolve(MovePocketMoney::class)->handle($this->user, $this->reserve, -5_000);

    expectRefusal('Money was moved in the Reserve pocket');
    expect($this->reserve->refresh()->balance)->toBe(95_000);
});

it('refuses when a pocket the closing touched was deleted since', function (): void {
    closeEarly();
    $this->savings->delete();

    expectRefusal('The Savings pocket was deleted');
});

it('refuses when a fixed item was ticked off in the new month', function (): void {
    closeEarly();
    $next = resolve(PeriodService::class)->current($this->user);
    resolve(ToggleLinePaid::class)->handle($this->user, $next, $this->user->budgetLines()->firstOrFail()->id);

    expectRefusal('A fixed item was already ticked off');
});

it('refuses after a base currency change', function (): void {
    closeEarly();
    CurrencyConversion::factory()->for($this->user)->create();

    expectRefusal('The base currency changed');
});

it('undoes the closing from the closed summary with the app confirmation', function (): void {
    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)->call('goTo', 3)->call('close')
        ->assertSee('Undo the closing')
        ->assertSee('data-test="reopen-closing"', false);

    Livewire::test('reopen-closing', ['periodId' => $this->period->id])
        ->call('dialog')
        ->assertReturned(fn (array $dialog): bool => $dialog['title'] === 'Undo the October closing?'
            && str_contains($dialog['body'], 'Reserve: '.money(-20_000))
            && str_contains($dialog['body'], 'Savings: '.money(-270_000))
            && str_contains($dialog['body'], 'The month is open again')
            && $dialog['confirm'] === 'Undo the closing')
        ->call('reopen')
        ->assertDispatched('app-toast', title: 'Closing undone.')
        ->assertRedirect(route('month', ['periodus' => $this->period->id]));

    expect($this->period->refresh()->isOpen())->toBeTrue();
});

it('offers the undo on the Month page and shows why it is refused', function (): void {
    closeEarly();
    resolve(MovePocketMoney::class)->handle($this->user, $this->reserve, -5_000);

    $this->get(route('month', ['periodus' => $this->period->id]))->assertSee('data-test="reopen-closing"', false);

    Livewire::test('reopen-closing', ['periodId' => $this->period->id])
        ->call('dialog')
        ->assertReturned(null)
        ->assertHasErrors('reopen')
        ->assertSee('Money was moved in the Reserve pocket');

    expect($this->period->refresh()->isOpen())->toBeFalse();
});

it('does not offer the undo for an older closing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00', 'Europe/Budapest'));
    closeEarly();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 18:00', 'Europe/Budapest'));
    resolve(PeriodCloser::class)->close($this->user, resolve(PeriodService::class)->current($this->user));

    $this->get(route('month', ['periodus' => $this->period->id]))
        ->assertOk()
        ->assertSee('data-test="close-summary"', false)
        ->assertDontSee('data-test="reopen-closing"', false);
});

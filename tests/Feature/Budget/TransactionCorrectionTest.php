<?php

use App\Actions\Budget\DeleteTransaction;
use App\Actions\Budget\PayFromPocket;
use App\Actions\Budget\RecordTransaction;
use App\Actions\Budget\RestoreTransaction;
use App\Actions\Budget\UpdateTransaction;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\PocketMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->groceries = Category::query()->where('name', 'Groceries')->firstOrFail();
    $this->today = CarbonImmutable::parse('2026-10-11');
    $this->spending = resolve(RecordTransaction::class)->handle($this->user, $this->fuel->id, 12_000, $this->today, 'Shell', 'b8a7c0c2-5a62-4a1c-8d3e-4b2a9a0e1f00');
});

/**
 * @return array<string, string>
 */
function correctionErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return array_map(fn (array $messages): string => $messages[0], $exception->errors());
    }

    return [];
}

it('changes the amount, category, date and note of a spending', function (): void {
    $updated = resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $this->groceries->id, 8_500, $this->today->subDay(), 'Aldi');

    expect($updated->amount)->toBe(8_500)
        ->and($updated->category_id)->toBe($this->groceries->id)
        ->and($updated->occurred_on->toDateString())->toBe('2026-10-10')
        ->and($updated->note)->toBe('Aldi')
        ->and($updated->period_id)->toBe($this->period->id);
});

it('moves the spending to the open period its new date falls in', function (): void {
    $previous = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-09-20'));

    $updated = resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $this->fuel->id, 12_000, CarbonImmutable::parse('2026-09-20'));

    expect($previous->isOpen())->toBeTrue()
        ->and($updated->period_id)->toBe($previous->id)
        ->and($updated->period_id)->not->toBe($this->period->id);
});

it('refuses to move a spending into a closed period', function (): void {
    $previous = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-09-20'));
    $previous->update(['status' => 'closed']);

    $errors = correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $this->fuel->id, 12_000, CarbonImmutable::parse('2026-09-20')));

    expect($errors)->toHaveKey('date')
        ->and($this->spending->refresh()->occurred_on->toDateString())->toBe('2026-10-11');
});

it('does not change a spending of a closed period', function (): void {
    $this->period->update(['status' => 'closed']);

    expect(correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $this->fuel->id, 1_000, $this->today)))->toHaveKey('date')
        ->and($this->spending->refresh()->amount)->toBe(12_000);
});

it('only accepts the user\'s own category and a positive amount', function (): void {
    $stranger = Category::factory()->for(User::factory())->create();

    expect(correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $stranger->id, 1_000, $this->today)))->toHaveKey('category')
        ->and(correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $this->fuel->id, 0, $this->today)))->toHaveKey('amount');
});

it('does not touch another user\'s spending', function (): void {
    $other = onboardedUser();

    expect(correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($other, $this->spending, $this->fuel->id, 1_000, $this->today)))->toHaveKey('transaction')
        ->and(correctionErrors(fn () => resolve(DeleteTransaction::class)->handle($other, $this->spending)))->toHaveKey('transaction');
});

it('removes a "didn\'t spend" mark on the new date', function (): void {
    $this->user->dayMarks()->create(['date' => '2026-10-09']);

    resolve(UpdateTransaction::class)->handle($this->user, $this->spending, $this->fuel->id, 12_000, CarbonImmutable::parse('2026-10-09'));

    expect($this->user->dayMarks()->count())->toBe(0);
});

it('takes a higher amount of a pocket-paid spending from the pocket and puts a lower one back', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 50_000]);
    $spending = resolve(PayFromPocket::class)->handle($this->user, $pocket, $this->fuel->id, 20_000);

    resolve(UpdateTransaction::class)->handle($this->user, $spending, $this->fuel->id, 25_000, $this->today->subDay());

    expect($pocket->refresh()->balance)->toBe(25_000)
        ->and(PocketMovement::query()->sole()->amount)->toBe(-25_000)
        ->and(PocketMovement::query()->sole()->occurred_on->toDateString())->toBe('2026-10-10');

    resolve(UpdateTransaction::class)->handle($this->user, $spending->refresh(), $this->fuel->id, 5_000, $this->today);

    expect($pocket->refresh()->balance)->toBe(45_000)
        ->and(PocketMovement::query()->sole()->amount)->toBe(-5_000);
});

it('refuses a pocket-paid amount the pocket cannot cover', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 20_000]);
    $spending = resolve(PayFromPocket::class)->handle($this->user, $pocket, $this->fuel->id, 20_000);

    expect(correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($this->user, $spending, $this->fuel->id, 20_001, $this->today)))->toHaveKey('amount')
        ->and($pocket->refresh()->balance)->toBe(0);
});

it('keeps the amount of an archived pocket\'s spending but lets the note change', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 50_000]);
    $spending = resolve(PayFromPocket::class)->handle($this->user, $pocket, $this->fuel->id, 20_000);
    $pocket->delete();

    expect(correctionErrors(fn () => resolve(UpdateTransaction::class)->handle($this->user, $spending, $this->fuel->id, 10_000, $this->today)))->toHaveKey('amount');

    resolve(UpdateTransaction::class)->handle($this->user, $spending->refresh(), $this->fuel->id, 20_000, $this->today, 'Tyres');

    expect($spending->refresh()->note)->toBe('Tyres');
});

it('deletes a spending and restores it with the same data', function (): void {
    $snapshot = resolve(DeleteTransaction::class)->handle($this->user, $this->spending);

    expect(Transaction::query()->count())->toBe(0);

    $restored = resolve(RestoreTransaction::class)->handle($this->user, $snapshot);

    expect($restored->amount)->toBe(12_000)
        ->and($restored->category_id)->toBe($this->fuel->id)
        ->and($restored->note)->toBe('Shell')
        ->and($restored->occurred_on->toDateString())->toBe('2026-10-11')
        ->and($restored->client_uuid)->toBe('b8a7c0c2-5a62-4a1c-8d3e-4b2a9a0e1f00')
        ->and($restored->period_id)->toBe($this->period->id);

    // A second undo does not record it twice.
    resolve(RestoreTransaction::class)->handle($this->user, $snapshot);

    expect(Transaction::query()->count())->toBe(1);
});

it('restores a pocket-paid spending with its pocket link and balance', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 50_000]);
    $spending = resolve(PayFromPocket::class)->handle($this->user, $pocket, $this->fuel->id, 20_000, 'Service');

    $snapshot = resolve(DeleteTransaction::class)->handle($this->user, $spending);

    expect($pocket->refresh()->balance)->toBe(50_000)
        ->and(PocketMovement::query()->count())->toBe(0);

    $restored = resolve(RestoreTransaction::class)->handle($this->user, $snapshot);

    expect($restored->pocket_id)->toBe($pocket->id)
        ->and($pocket->refresh()->balance)->toBe(30_000)
        ->and(PocketMovement::query()->sole()->transaction_id)->toBe($restored->id)
        ->and(PocketMovement::query()->sole()->note)->toBe('Service');
});

it('does not delete or restore spending of a closed period', function (): void {
    $snapshot = resolve(DeleteTransaction::class)->handle($this->user, $this->spending);
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00', 'Europe/Budapest'));
    resolve(PeriodCloser::class)->close($this->user, $this->period->refresh(), $this->period->income());

    expect(correctionErrors(fn () => resolve(RestoreTransaction::class)->handle($this->user, $snapshot)))->toHaveKey('date');

    $closedSpending = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create();

    expect(correctionErrors(fn () => resolve(DeleteTransaction::class)->handle($this->user, $closedSpending)))->toHaveKey('date')
        ->and(Transaction::query()->count())->toBe(1);
});

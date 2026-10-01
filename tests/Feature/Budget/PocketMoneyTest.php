<?php

use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\PayFromPocket;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Services\OverviewService;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->reserve = Pocket::factory()->for($this->user)->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 100_000]);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
});

it('tops up the period budget with a withdrawal', function (): void {
    $before = resolve(OverviewService::class)->forUser($this->user)->forecast->expectedLeftover;

    resolve(MovePocketMoney::class)->handle($this->user, $this->reserve, -30_000, null, toBudget: true);

    $period = resolve(PeriodService::class)->current($this->user);

    expect($this->reserve->refresh()->balance)->toBe(70_000)
        ->and(resolve(PeriodService::class)->topUps($period))->toBe(30_000)
        ->and(resolve(OverviewService::class)->forUser($this->user)->forecast->expectedLeftover)->toBe($before + 30_000);
});

it('does not touch the budget for a plain withdrawal', function (): void {
    resolve(MovePocketMoney::class)->handle($this->user, $this->reserve, -30_000, null, toBudget: false);

    expect(resolve(PeriodService::class)->topUps(resolve(PeriodService::class)->current($this->user)))->toBe(0);
});

it('refuses to withdraw more than the balance', function (): void {
    resolve(MovePocketMoney::class)->handle($this->user, $this->reserve, -150_000, null, toBudget: true);
})->throws(ValidationException::class);

it('pays a spending from a pocket without using up the budget', function (): void {
    $before = resolve(OverviewService::class)->forUser($this->user)->forecast;

    $transaction = resolve(PayFromPocket::class)->handle($this->user, $this->reserve, $this->fuel->id, 40_000, 'Car service');

    $after = resolve(OverviewService::class)->forUser($this->user)->forecast;

    expect($transaction->pocket_id)->toBe($this->reserve->id)
        ->and($this->reserve->refresh()->balance)->toBe(60_000)
        ->and($after->variableSpent)->toBe($before->variableSpent)
        ->and($after->expectedLeftover)->toBe($before->expectedLeftover);

    $this->get(route('month'))->assertSee('paid from Reserve');
});

it('puts the money back when a pocket-paid spending is deleted', function (): void {
    $transaction = resolve(PayFromPocket::class)->handle($this->user, $this->reserve, $this->fuel->id, 40_000);

    $transaction->delete();

    expect($this->reserve->refresh()->balance)->toBe(100_000)
        ->and($this->reserve->movements()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('handles both withdrawal modes from the pockets screen', function (): void {
    $page = Livewire::test('pages::pockets')->instance();

    expect($page->movePocketMoney($this->reserve->id, -1, '10000', null, resolve(MovePocketMoney::class), true)['ok'])->toBeTrue()
        ->and($page->payFromPocket($this->reserve->id, '5000', $this->fuel->id, null, resolve(PayFromPocket::class))['ok'])->toBeTrue()
        ->and($page->payFromPocket($this->reserve->id, '5000', null, null, resolve(PayFromPocket::class))['errors'])->toHaveKey('category')
        ->and($this->reserve->refresh()->balance)->toBe(85_000);
});

it('links "cover from the reserve" with the missing amount', function (): void {
    Transaction::factory()->for($this->user)->for(resolve(PeriodService::class)->current($this->user))->for($this->fuel)
        ->create(['amount' => 400_000, 'occurred_on' => '2026-10-05']);

    $expected = resolve(OverviewService::class)->forUser($this->user)->forecast->expectedLeftover;

    $this->get(route('dashboard'))->assertSee('fedezes='.(-$expected), false);
    $this->get(route('pockets', ['fedezes' => -$expected]))->assertOk()->assertSee('pocketId', false);
});

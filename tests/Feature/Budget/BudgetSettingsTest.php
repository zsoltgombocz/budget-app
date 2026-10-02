<?php

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Pocket;
use Livewire\Livewire;

it('updates the period and the base currency', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->get(route('budget.edit'))->assertOk()->assertSee(route('plan'), false);

    Livewire::test('pages::settings.budget')
        ->set('periodMode', 'payday')
        ->set('paydayDay', 10)
        ->set('currency', 'EUR')
        ->assertHasNoErrors()
        ->assertNotDispatched('app-toast');

    $settings = $user->settings()->refresh();

    expect($settings->period_mode)->toBe(PeriodMode::Payday)
        ->and($settings->payday_day)->toBe(10)
        ->and($settings->currency)->toBe(Currency::EUR);
});

it('saves language and time zone from the appearance page right away', function (): void {
    $user = onboardedUser(['locale' => 'hu']);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('timezone', 'Europe/London')
        ->assertHasNoErrors()
        ->set('locale', 'en')
        ->assertRedirect(route('appearance.edit'));

    $settings = $user->settings()->refresh();

    expect($settings->timezone)->toBe('Europe/London')
        ->and($settings->locale)->toBe('en');
});

it('shows the month-end leftover on the plan and saves the leftover rule there', function (): void {
    $user = onboardedUser(['reserve_pct' => 100]);
    $this->actingAs($user);
    $account = $user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);
    $reserve = Pocket::factory()->for($user)->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 0, 'target_amount' => null]);
    $category = Category::factory()->for($user)->create(['name' => 'Reserve', 'type' => 'sinking']);
    BudgetLine::factory()->for($user)->for($category)->create(['amount' => 25_000, 'pocket_id' => $reserve->id]);

    // 500 000 income − 350 000 plan − 25 000 reserve saving = 125 000 left.
    $this->get(route('plan'))->assertOk()
        ->assertSee('data-test="month-end"', false)
        ->assertSee(money(125_000));

    Livewire::test('pages::plan')
        ->call('setReservePct', 50)
        ->call('setSurplusTarget', 'account:'.$account->id)
        ->assertNotDispatched('app-toast')
        ->assertSee(money(25_000 + 62_500));

    $settings = $user->settings()->refresh();
    expect($settings->reserve_pct)->toBe(50)->and($settings->surplus_account_id)->toBe($account->id);
});

it('ignores another user\'s leftover target', function (): void {
    $this->actingAs(onboardedUser());
    $foreign = onboardedUser()->accounts()->create(['name' => 'X', 'type' => 'investment', 'currency' => 'HUF']);

    Livewire::test('pages::plan')->call('setSurplusTarget', 'account:'.$foreign->id);

    expect(auth()->user()->settings()->refresh()->surplus_account_id)->toBeNull();
});

it('creates the reserve from the plan with its monthly saving and target', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $page = Livewire::test('pages::plan');
    expect($page->instance()->reserveData()['exists'])->toBeFalse();

    $result = $page->instance()->saveReserve(['monthly' => '25000', 'target' => '300000', 'fixed' => '']);
    expect($result['ok'])->toBeTrue();

    $reserve = Pocket::query()->where('is_reserve', true)->sole();
    expect($reserve->target_amount)->toBe(300_000)
        ->and(BudgetLine::query()->where('pocket_id', $reserve->id)->value('amount'))->toBe(25_000);

    // Saving again updates the same line instead of adding one.
    $page->instance()->saveReserve(['monthly' => '30000', 'target' => '', 'fixed' => '']);
    expect(BudgetLine::query()->where('pocket_id', $reserve->id)->count())->toBe(1)
        ->and(BudgetLine::query()->where('pocket_id', $reserve->id)->value('amount'))->toBe(30_000)
        ->and($reserve->refresh()->target_amount)->toBeNull();

    expect($page->instance()->saveReserve(['monthly' => 'sok', 'target' => '', 'fixed' => ''])['errors'])->toHaveKey('monthly');
});

it('switches the leftover rule between a share and a fixed amount', function (): void {
    $user = onboardedUser(['reserve_pct' => 50]);
    $this->actingAs($user);
    Pocket::factory()->for($user)->create(['is_reserve' => true, 'balance' => 0, 'target_amount' => null]);

    $page = Livewire::test('pages::plan')->call('setReserveMode', 'fixed');
    expect($user->settings()->refresh()->reserve_fixed)->toBe(0);

    $page->instance()->saveReserve(['monthly' => '', 'target' => '', 'fixed' => '40000']);
    expect($user->settings()->refresh()->reserve_fixed)->toBe(40_000)
        ->and($page->instance()->monthEnd->allocation->toReserve)->toBe(40_000);

    $page->call('setReserveMode', 'pct');
    expect($user->settings()->refresh()->reserve_fixed)->toBeNull();
});

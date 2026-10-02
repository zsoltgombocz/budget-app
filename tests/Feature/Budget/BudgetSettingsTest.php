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
        ->call('save')
        ->assertHasNoErrors();

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

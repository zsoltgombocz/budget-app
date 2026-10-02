<?php

use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\User;
use App\Support\OnboardingItems;
use Database\Seeders\CategoryTemplateSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(CategoryTemplateSeeder::class);
});

it('sends new users to the wizard', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
    $this->get(route('onboarding'))->assertOk();
});

it('builds a combined plan from the answers: shared costs and a loan in one pass', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '609 000')->call('next')
        ->set('periodMode', 'payday')->set('paydayDay', 31)->call('next')
        ->set('currency', 'HUF')->call('next')
        ->assertSet('step', 4)
        ->set('amounts.0', '180000')->call('next')
        ->set('shared', true)->set('amounts.6', '120000')->call('next')
        ->set('hasLoan', true)->set('amounts.8', '87549')->call('next')
        ->set('amounts.10', '90000')->call('next')
        ->assertSet('step', 8)
        ->set('amounts.14', '20000')
        ->set('reserveTarget', '300000')
        ->set('reservePct', 50)
        ->set('surplusTarget', 'investment')
        ->call('finish')
        ->assertHasNoErrors()
        ->assertRedirect(route('notifications.onboarding'));

    $settings = $user->refresh()->settings();
    $names = $user->categories()->pluck('name');

    expect($settings->isOnboarded())->toBeTrue()
        ->and($settings->income)->toBe(609_000)
        ->and($settings->period_mode)->toBe(PeriodMode::Payday)
        ->and($settings->payday_day)->toBe(31)
        ->and($settings->reserve_pct)->toBe(50)
        ->and($names)->toContain('Shared contribution', 'Shared pocket', 'Loan', 'Prepayment fund', 'Groceries', 'Reserve')
        ->and($user->budgetLines()->sum('amount'))->toBe(180_000 + 120_000 + 87_549 + 90_000 + 20_000)
        ->and($user->loans()->value('installment'))->toBe(87_549)
        ->and($user->pockets()->where('is_reserve', true)->value('target_amount'))->toBe(300_000)
        ->and($user->periods()->count())->toBe(1);
});

it('leaves out the groups answered with no', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')
        ->set('included.1', false)->call('next')
        ->set('shared', false)->call('next')
        ->set('hasLoan', false)->call('next')
        ->call('next')
        ->call('finish')
        ->assertHasNoErrors();

    $names = $user->categories()->pluck('name');

    expect($names)->not->toContain('Utilities', 'Shared contribution', 'Loan', 'Prepayment fund')
        ->and($names)->toContain('Housing', 'Groceries', 'Reserve')
        ->and($user->loans()->count())->toBe(0);
});

it('offers subscriptions, gym and insurance switched off', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')
        ->assertSet('included.3', false)->assertSet('included.4', false)->assertSet('included.5', false)
        ->set('included.3', true)->set('amounts.3', '6990')
        ->call('next')
        ->set('shared', false)->call('next')
        ->set('hasLoan', false)->call('next')
        ->call('next')
        ->call('finish')
        ->assertHasNoErrors();

    $names = $user->categories()->pluck('name');

    expect($names)->toContain('Subscriptions')
        ->and($names)->not->toContain('Gym and sport', 'Insurance')
        ->and(BudgetLine::query()->whereRelation('category', 'name', 'Subscriptions')->value('amount'))->toBe(6_990);
});

it('asks for a yes or no before moving past a question', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 5)
        ->call('next')
        ->assertHasErrors('shared')
        ->assertSet('step', 5)
        ->assertDontSee('Shared contribution')
        ->set('shared', true)
        ->assertSee('Shared contribution');
});

it('validates each step before moving on', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('income', 'sok')
        ->call('next')
        ->assertHasErrors('income')
        ->assertSet('step', 1);
});

it('can be skipped for an empty plan', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '450 000')
        ->call('skip')
        ->assertRedirect(route('notifications.onboarding'));

    expect($user->settings()->refresh()->isOnboarded())->toBeTrue()
        ->and($user->settings()->income)->toBe(450_000)
        ->and($user->categories()->count())->toBe(0)
        ->and($user->periods()->count())->toBe(1);
});

it('keeps a reserve pocket with a target even without a monthly amount', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '1000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->set('currency', 'EUR')->call('next')
        ->call('next')
        ->set('shared', false)->call('next')
        ->set('hasLoan', false)->call('next')
        ->call('next')
        ->set('reserveTarget', '2500.50')
        ->set('surplusTarget', 'pocket')
        ->call('finish')
        ->assertHasNoErrors();

    expect($user->settings()->refresh()->income)->toBe(100_000)
        ->and($user->pockets()->where('is_reserve', true)->value('target_amount'))->toBe(250_050)
        ->and($user->settings()->surplus_pocket_id)->not->toBeNull();
});

it('creates no reserve pocket when the reserve is switched off', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '1000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')->call('next')
        ->set('shared', false)->call('next')
        ->set('hasLoan', false)->call('next')
        ->call('next')
        ->set('included.14', false)
        ->set('reserveTarget', '300000')
        ->assertSee(__('No reserve pocket: the whole month-end leftover goes to the target below, and nothing covers a month when you spend more than came in. You can add one later under Pockets.'))
        ->call('finish')
        ->assertHasNoErrors();

    expect($user->pockets()->where('is_reserve', true)->exists())->toBeFalse();
});

it('saves the optional loan details from the loan step', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '609000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')->call('next')
        ->set('shared', false)->call('next')
        ->set('hasLoan', true)
        ->set('amounts.8', '87549')
        ->set('loanThm', '99,9999')
        ->call('next')
        ->assertHasErrors('loanThm')
        ->set('loanPrincipal', '10 000 000')
        ->set('loanThm', '7,9')
        ->set('loanMonths', '180')
        ->call('next')
        ->call('next')
        ->call('finish')
        ->assertHasNoErrors();

    $loan = $user->loans()->sole();

    expect($loan->installment)->toBe(87_549)
        ->and($loan->principal_balance)->toBe(10_000_000)
        ->and($loan->thm)->toBe(7.9)
        ->and($loan->remaining_months)->toBe(180);
});

it('works out where the leftover of the entered plan would go', function (): void {
    $this->actingAs(User::factory()->create());

    $wizard = Livewire::test('pages::onboarding')
        ->set('income', '500000')
        ->set('shared', false)
        ->set('hasLoan', false)
        ->set('amounts.0', '200000')
        ->set('amounts.10', '100000')
        ->set('amounts.14', '20000')
        ->set('reservePct', 50)
        ->set('reserveTarget', '50000');

    // 500 000 - 320 000 = 180 000 left; half is 90 000, capped at the 50 000 target.
    expect($wizard->instance()->leftoverPreview)->toBe(['leftover' => 180_000, 'toReserve' => 50_000, 'toSurplus' => 130_000, 'reserveOn' => true]);

    $wizard->set('included.14', false);
    expect($wizard->instance()->leftoverPreview)->toBe(['leftover' => 200_000, 'toReserve' => 0, 'toSurplus' => 200_000, 'reserveOn' => false]);
});

it('skips the wizard once onboarded', function (): void {
    $this->actingAs(onboardedUser());

    Livewire::test('pages::onboarding')->assertRedirect(route('dashboard'));
});

it('shows a hint for every line', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 4)
        ->assertSee('Leave 0 if they are paid from a joint account.');
});

it('explains what the leftover targets mean', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 8)
        ->assertSee('listed as a manual transfer')
        ->assertSee('added to a “Savings” pocket');
});

it('has Hungarian text for every line it offers', function (): void {
    $hungarian = json_decode((string) file_get_contents(lang_path('hu.json')), true);

    $missing = collect(OnboardingItems::all())
        ->flatMap(fn (array $entry): array => array_filter([$entry['item']['name'], $entry['item']['hint'], $entry['item']['pocket']['name'] ?? null]))
        ->reject(fn (string $text): bool => isset($hungarian[$text]))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

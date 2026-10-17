<?php

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
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
        ->set('shared', true)->set('amounts.6', '120000')->set('amounts.7', '30000')->call('next')
        ->set('hasLoan', true)->set('amounts.8', '87549')->set('amounts.9', '25000')->call('next')
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
        ->and($names)->not->toContain('Utilities', 'Phone and internet')
        ->and($user->budgetLines()->sum('amount'))->toBe(180_000 + 120_000 + 30_000 + 87_549 + 25_000 + 90_000 + 20_000)
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
        ->set('amounts.0', '150000')->set('included.1', false)->call('next')
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
        ->assertSee('Choose one to continue.')
        ->call('next')
        ->assertHasErrors('shared')
        ->assertSet('step', 5)
        ->assertDontSee('Shared contribution')
        ->set('shared', true)
        ->assertSee('Shared contribution')
        ->assertDontSee('Choose one to continue.');
});

it('shows a zero reserve saving without a minus sign', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 9)
        ->set('income', '500000')
        ->set('shared', false)
        ->set('hasLoan', false)
        ->set('amounts.14', '0')
        ->assertSeeHtml('data-test="preview-reserve-monthly">'.money(0, Currency::HUF).'<');
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

it('saves a fixed leftover amount for the reserve', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')->call('next')
        ->set('shared', false)->call('next')
        ->set('hasLoan', false)->call('next')
        ->call('next')
        ->set('reserveMode', 'fixed')->set('reserveFixed', '30000')
        ->call('finish')
        ->assertHasNoErrors();

    expect($user->settings()->refresh()->reserve_fixed)->toBe(30_000);
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
        ->assertSee(__('No reserve pocket: the whole month-end leftover goes where you choose on the next step. You can add one later under Pockets.'))
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

it('starts with no extra share of the leftover for the reserve', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')->assertSet('reservePct', 0);
});

it('works out where the leftover of the entered plan would go', function (): void {
    $this->actingAs(User::factory()->create());

    $wizard = Livewire::test('pages::onboarding')
        ->set('step', 9)
        ->set('income', '500000')
        ->set('shared', false)
        ->set('hasLoan', false)
        ->set('amounts.0', '200000')
        ->set('amounts.10', '100000')
        ->set('amounts.14', '20000')
        ->set('reservePct', 50)
        ->set('reserveTarget', '50000');

    // 500 000 - 300 000 planned - 20 000 put aside = 180 000 left; half would be 90 000, but the
    // 50 000 target leaves room for only 30 000 next to the monthly 20 000.
    expect($wizard->instance()->leftoverPreview)->toBe(['income' => 500_000, 'planned' => 300_000, 'reserveMonthly' => 20_000, 'leftover' => 180_000, 'toReserve' => 30_000, 'toSurplus' => 150_000, 'reserveOn' => true])
        ->and($wizard->html())->toContain(money(50_000));

    $wizard->set('reserveMode', 'fixed')->set('reserveFixed', '10000');
    expect($wizard->instance()->leftoverPreview['toReserve'])->toBe(10_000);
    $wizard->set('reserveMode', 'pct');

    $wizard->set('included.14', false);
    expect($wizard->instance()->leftoverPreview)->toBe(['income' => 500_000, 'planned' => 300_000, 'reserveMonthly' => 0, 'leftover' => 200_000, 'toReserve' => 0, 'toSurplus' => 200_000, 'reserveOn' => false]);
});

it('leaves the reserve row out of the leftover split while nothing goes to the reserve', function (): void {
    $this->actingAs(User::factory()->create());

    $wizard = Livewire::test('pages::onboarding')
        ->set('step', 9)->set('income', '500000')->set('shared', false)->set('hasLoan', false)
        ->set('amounts.0', '200000')->set('amounts.14', '')->set('reservePct', 0)
        ->assertDontSeeHtml('data-test="preview-reserve-split"');

    $wizard->set('reservePct', 25)->assertSeeHtml('data-test="preview-reserve-split"');
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

it('tells that more lines can be added later on the plan', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 4)->assertSee('data-test="more-later"', false)
        ->set('step', 7)->assertSee('data-test="more-later"', false)
        ->set('step', 5)->set('shared', true)->assertDontSee('data-test="more-later"', false);
});

it('explains what the leftover targets mean', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 9)
        ->assertSee('Nothing is moved: the money simply stays on your account.')
        ->assertSee('If you have an investment account')
        ->assertSee('remind you to move it to your investment account')
        ->assertSee('goes into a “Savings” pocket');
});

it('names the prepayment step in the currency picked, kept on one line', function (string $currency, string $step): void {
    $this->actingAs(User::factory()->create());

    app()->setLocale('hu');

    Livewire::test('pages::onboarding')
        ->set('currency', $currency)
        ->set('step', 6)->set('hasLoan', true)
        ->assertSee('Ha eléri a lépcsőt ('.$step.')');
})->with([
    'forint' => ['HUF', "500\u{00A0}000\u{00A0}Ft"],
    'euro' => ['EUR', "5\u{00A0}000,00\u{00A0}EUR"],
]);

it('has Hungarian text for every line it offers', function (): void {
    $hungarian = json_decode((string) file_get_contents(lang_path('hu.json')), true);

    $missing = collect(OnboardingItems::all())
        ->flatMap(fn (array $entry): array => array_filter([$entry['item']['name'], $entry['item']['hint'], $entry['item']['pocket']['name'] ?? null]))
        ->reject(fn (string $text): bool => isset($hungarian[$text]))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('keeps the answers as a draft and resumes them on the next visit', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')
        ->set('amounts.0', '180000')->set('included.1', false);

    Livewire::test('pages::onboarding')
        ->assertSet('step', 4)
        ->assertSet('income', '500000')
        ->assertSet('periodMode', 'calendar')
        ->assertSet('amounts.0', '180000')
        ->assertSet('included.1', false)
        ->assertSee('data-test="onboarding-resumed"', false);
});

it('does not mention a draft on the first visit', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')->assertDontSee('data-test="onboarding-resumed"', false);
});

it('starts over from the first step and forgets the draft', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')->set('income', '500000')->call('next')->set('periodMode', 'calendar');

    Livewire::test('pages::onboarding')
        ->call('restart')
        ->assertSet('step', 1)
        ->assertSet('income', '')
        ->assertSet('periodMode', 'payday')
        ->assertDontSee('data-test="onboarding-resumed"', false);

    expect($user->settings()->refresh()->onboarding_draft)->toBeNull();
});

it('ignores a malformed draft and keeps the defaults', function (): void {
    $user = User::factory()->create();
    $user->settings()->update(['onboarding_draft' => ['step' => 99, 'income' => 5, 'currency' => 'XXX', 'amounts' => ['x' => 1], 'included' => 'no']]);
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->assertSet('step', 9)
        ->assertSet('income', '')
        ->assertSet('currency', 'HUF')
        ->assertSet('amounts.0', '')
        ->assertSet('included.0', true);
});

it('clears the draft and shows the intro once onboarding is done', function (string $path): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $wizard = Livewire::test('pages::onboarding')->set('income', '500000');

    if ($path === 'finish') {
        $wizard->call('next')
            ->set('periodMode', 'calendar')->call('next')
            ->call('next')->call('next')
            ->set('shared', false)->call('next')
            ->set('hasLoan', false)->call('next')
            ->call('next');
    }

    $wizard->call($path)->assertHasNoErrors();

    $settings = $user->settings()->refresh();

    expect($settings->onboarding_draft)->toBeNull()
        ->and($settings->needsIntro())->toBeTrue();
})->with(['finish', 'skip']);

it('asks for the income in plain words and shows how to enter it', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->assertSee('Tap and type it in')
        ->call('next')
        ->assertHasErrors(['income' => 'Enter your monthly net income.']);
});

it('keeps Next disabled until a yes or no question is answered', function (): void {
    $this->actingAs(User::factory()->create());

    $nextIsDisabled = fn (string $html): bool => preg_match('/<button(?=[^>]*data-test="onboarding-next")(?=[^>]*\sdisabled[\s=>])[^>]*>/', $html) === 1;

    $wizard = Livewire::test('pages::onboarding')->set('step', 5);
    expect($nextIsDisabled($wizard->html()))->toBeTrue();

    $wizard->set('shared', false);
    expect($nextIsDisabled($wizard->html()))->toBeFalse();

    $wizard->set('step', 4);
    expect($nextIsDisabled($wizard->html()))->toBeFalse();
});

it('tells once at the top of the step what an amount left at 0 means', function (): void {
    $this->actingAs(User::factory()->create());

    $wizard = Livewire::test('pages::onboarding')
        ->set('step', 4)->assertSee('A line left at 0 stays out of the plan');
    expect(substr_count($wizard->html(), 'the app reminds you that morning'))->toBe(1);

    $wizard
        ->set('step', 7)->assertSee('A budget left at 0 means no budget for it')
        ->assertSeeHtmlInOrder(['data-test="zero-hint"', 'data-test="template-item"']);
    expect(substr_count($wizard->html(), 'data-test="zero-hint"'))->toBe(1);
});

it('leaves fixed lines at 0 out of the plan but keeps budgets and the reserve at 0', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')
        ->set('amounts.0', '150000')->call('next')
        ->set('shared', true)->set('amounts.6', '50000')->call('next')
        ->set('hasLoan', true)->call('next')
        ->call('next')
        ->call('finish')
        ->assertHasNoErrors();

    $names = $user->categories()->pluck('name');

    expect($names)->toContain('Housing', 'Shared contribution', 'Groceries', 'Other', 'Reserve')
        ->and($names)->not->toContain('Utilities', 'Phone and internet', 'Shared pocket', 'Loan', 'Prepayment fund')
        ->and($user->loans()->count())->toBe(0)
        ->and($user->pockets()->where('is_reserve', true)->exists())->toBeTrue();
});

it('keeps the leftover on the account unless another target is chosen', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->assertSet('surplusTarget', 'none')
        ->set('income', '500000')
        ->set('shared', false)
        ->set('hasLoan', false)
        ->call('finish')
        ->assertHasNoErrors();

    $settings = $user->refresh()->settings();

    expect($settings->surplus_account_id)->toBeNull()
        ->and($settings->surplus_pocket_id)->toBeNull()
        ->and($user->accounts()->count())->toBe(0)
        ->and($user->pockets()->where('is_reserve', false)->count())->toBe(0);
});

it('asks about the reserve and the leftover on two separate steps', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 8)
        ->assertSee('Would you like a reserve?')
        ->assertSee('data-test="reserve-card"', false)
        ->assertDontSee('data-test="surplus-options"', false)
        ->set('included.14', false)
        ->assertDontSee('data-test="reserve-card"', false)
        ->call('next')
        ->assertSet('step', 9)
        ->assertSee('What should happen to the money left at month end?')
        ->assertSee('Where the leftover goes')
        ->assertSee('data-test="surplus-options"', false)
        ->assertSee('aria-valuetext="Step 9 of 9"', false);
});

it('saves the optional due day of a fixed line', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->call('next')->call('next')
        ->assertSee('Which day do you pay it?')
        ->set('amounts.0', '180000')->set('dueDays.0', 5)
        ->set('amounts.1', '30000')
        ->set('shared', false)->set('hasLoan', false)
        ->call('finish')
        ->assertHasNoErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Housing')->value('due_day'))->toBe(5)
        ->and(BudgetLine::query()->whereRelation('category', 'name', 'Utilities')->value('due_day'))->toBeNull();
});

it('rejects a due day outside the month', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('step', 4)
        ->set('dueDays.0', 32)
        ->call('next')
        ->assertHasErrors('dueDays.0')
        ->assertSet('step', 4);
});

it('does not keep the old investment default from a draft saved before the leftover question', function (): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['onboarded_at' => null, 'onboarding_draft' => ['step' => 5, 'income' => '400000', 'surplusTarget' => 'investment']]);
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->assertSet('step', 5)
        ->assertSet('income', '400000')
        ->assertSet('surplusTarget', 'none');
});

it('keeps an investment target chosen in a current draft', function (): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['onboarded_at' => null, 'onboarding_draft' => ['version' => 2, 'step' => 9, 'surplusTarget' => 'investment']]);
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->assertSet('step', 9)
        ->assertSet('surplusTarget', 'investment');
});

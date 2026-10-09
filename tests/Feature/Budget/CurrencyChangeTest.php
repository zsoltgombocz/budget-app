<?php

use App\Actions\Budget\ChangeBaseCurrency;
use App\Actions\Budget\DeletePocket;
use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\ResetBudget;
use App\Enums\Currency;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\CurrencyConversion;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\PeriodLineStatus;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Every money column of the user, keyed by table and row id, JSON decoded.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function moneySnapshot(User $user): array
{
    $columns = [
        'budget_settings' => ['income', 'reserve_fixed'],
        'budget_lines' => ['amount', 'amount_avg', 'amount_max', 'orig_amount', 'orig_currency'],
        'periods' => ['income_planned', 'income_actual', 'plan_snapshot'],
        'period_line_statuses' => ['amount_actual'],
        'period_closes' => ['planned_total', 'actual_total', 'leftover', 'to_reserve', 'to_invest', 'from_reserve', 'breakdown'],
        'transactions' => ['amount'],
        'pockets' => ['balance', 'target_amount', 'prepay_step'],
        'pocket_movements' => ['amount'],
        'loans' => ['principal_balance', 'installment', 'insurance'],
        'loan_events' => ['amount', 'principal_after', 'installment_after'],
    ];
    $snapshot = [];

    foreach ($columns as $table => $tableColumns) {
        foreach (DB::table($table)->where('user_id', $user->id)->orderBy('id')->get(['id', ...$tableColumns]) as $row) {
            $values = (array) $row;
            unset($values['id']);

            foreach (['plan_snapshot', 'breakdown'] as $json) {
                if (isset($values[$json]) && is_string($values[$json])) {
                    $values[$json] = json_decode($values[$json], true);
                }
            }

            $snapshot[$table][(int) $row->id] = $values;
        }
    }

    return $snapshot;
}

beforeEach(function (): void {
    $this->eurRate = '400,00';
    $this->usdRate = '350,00';
    $this->mnbDown = false;
    Http::fake(fn () => $this->mnbDown
        ? Http::response('Service Unavailable', 503)
        : Http::response(mnbSoapResponse(['EUR' => [1, $this->eurRate], 'USD' => [1, $this->usdRate]])));

    // A September with odd amounts (so any rounding loss would show), closed at month end.
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'Europe/Budapest'));
    $this->user = onboardedUser(['reserve_fixed' => 40_003]);
    $this->actingAs($this->user);
    $september = resolve(PeriodService::class)->current($this->user);
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();

    $this->transaction = Transaction::factory()->for($this->user)->for($september)->for($fuel)->create(['amount' => 12_345, 'occurred_on' => '2026-09-09']);
    PeriodLineStatus::factory()->for($this->user)->for($september)->for($rent)->create(['amount_actual' => 199_999]);

    $this->holiday = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 100_003, 'target_amount' => 333_333, 'prepay_step' => 250_001]);
    resolve(MovePocketMoney::class)->handle($this->user, $this->holiday, 33_333);
    $archived = Pocket::factory()->for($this->user)->create(['name' => 'Old', 'balance' => 7_777]);
    resolve(DeletePocket::class)->handle($archived);

    $loan = Loan::factory()->for($this->user)->create(['principal_balance' => 5_000_001, 'installment' => 80_017, 'insurance' => 2_001]);
    LoanEvent::factory()->for($this->user)->for($loan)->create(['amount' => 100_007, 'principal_after' => 4_899_994, 'installment_after' => 78_123]);

    $netflix = Category::factory()->for($this->user)->create(['name' => 'Netflix', 'type' => 'fixed']);
    $this->netflix = BudgetLine::factory()->for($this->user)->for($netflix)->create(['amount' => 5_001, 'orig_amount' => 1_299, 'orig_currency' => 'EUR']);

    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:00', 'Europe/Budapest'));
    resolve(PeriodCloser::class)->close($this->user, $september, 512_345);

    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Budapest'));
    $this->user->refresh();

    $this->switchTo = function (string $code): void {
        $page = Livewire::test('pages::settings.budget')->instance();
        expect($page->previewCurrency($code, resolve(ChangeBaseCurrency::class)))->not->toBeNull();
        $page->changeCurrency(resolve(ChangeBaseCurrency::class));
        expect($this->user->settings()->refresh()->currency->value)->toBe($code);
    };
});

it('converts every amount at the MNB rate only after confirmation', function (): void {
    $before = moneySnapshot($this->user);
    $page = Livewire::test('pages::settings.budget')->instance();

    $dialog = $page->previewCurrency('EUR', resolve(ChangeBaseCurrency::class));

    expect($dialog['title'])->toBe('Convert every amount to EUR?')
        ->and($dialog['highlight'])->toBe('MNB rate 2026-10-08: 1 € = 400.00 HUF')
        ->and($dialog['body'])->toContain('Plan lines whose original amount is in EUR take exactly that amount.')
        ->and($dialog['body'])->toContain('If you switch back to HUF later, the original amounts come back exactly')
        ->and(moneySnapshot($this->user))->toBe($before)
        ->and($this->user->settings()->refresh()->currency)->toBe(Currency::HUF);

    $page->changeCurrency(resolve(ChangeBaseCurrency::class));

    $settings = $this->user->settings()->refresh();
    $close = $this->user->periodCloses()->sole();
    $september = $close->period;

    expect($settings->currency)->toBe(Currency::EUR)
        ->and($settings->income)->toBe(125_000)            // 500 000 Ft
        ->and($settings->reserve_fixed)->toBe(10_001)      // 40 003 Ft = 100.0075 €
        ->and($this->transaction->refresh()->amount)->toBe(3_086) // 12 345 Ft = 30.8625 €
        ->and($this->holiday->refresh()->balance)->toBe(33_334) // 133 336 Ft
        ->and($this->holiday->target_amount)->toBe(83_333)
        ->and($this->holiday->prepay_step)->toBe(62_500)
        ->and(Pocket::withTrashed()->where('name', 'Old')->value('balance'))->toBe(1_944)
        ->and(Loan::query()->sole()->only(['principal_balance', 'installment', 'insurance']))->toBe(['principal_balance' => 1_250_000, 'installment' => 20_004, 'insurance' => 500])
        ->and(LoanEvent::query()->sole()->amount)->toBe(25_002)
        ->and(PeriodLineStatus::query()->sole()->amount_actual)->toBe(50_000)
        ->and($this->netflix->refresh()->amount)->toBe(1_299)
        ->and($september->income_actual)->toBe(128_086)
        ->and(collect($september->plan_snapshot)->firstWhere('category_name', 'Rent')['amount'] ?? null)->toBe(50_000)
        ->and($close->breakdown['income_actual'])->toBe(128_086)
        ->and(collect($close->breakdown['categories'])->firstWhere('name', 'Rent')['planned'] ?? null)->toBe(50_000)
        ->and($close->actual_total)->toBe(resolve(CurrencyConverter::class)->convert($before['period_closes'][$close->id]['actual_total'], Currency::HUF, Currency::EUR, '1', '400'))
        ->and(CurrencyConversion::query()->sole()->only(['from_rate', 'to_rate']))->toBe(['from_rate' => '1.00000000', 'to_rate' => '400.00000000']);
});

it('gives back exactly the original amounts when switching back, again and again', function (): void {
    $original = moneySnapshot($this->user);

    ($this->switchTo)('EUR');
    $this->eurRate = '411,37';
    ($this->switchTo)('HUF');
    expect(moneySnapshot($this->user))->toBe($original);

    ($this->switchTo)('EUR');
    ($this->switchTo)('HUF');

    expect(moneySnapshot($this->user))->toBe($original)
        ->and(CurrencyConversion::query()->count())->toBe(0);
});

it('goes back through several currencies to the original amounts', function (): void {
    $original = moneySnapshot($this->user);

    ($this->switchTo)('EUR');
    $inEuro = moneySnapshot($this->user);
    ($this->switchTo)('USD');
    ($this->switchTo)('EUR');
    expect(moneySnapshot($this->user))->toBe($inEuro);

    ($this->switchTo)('USD');
    ($this->switchTo)('HUF');
    expect(moneySnapshot($this->user))->toBe($original);
});

it('converts what was added or changed after the switch back at the rate used then', function (): void {
    $original = moneySnapshot($this->user);
    ($this->switchTo)('EUR');

    $this->eurRate = '500,00';
    $october = resolve(PeriodService::class)->current($this->user);
    $coffee = Transaction::factory()->for($this->user)->for($october)->for(Category::query()->where('name', 'Groceries')->firstOrFail())->create(['amount' => 1_000]);
    resolve(MovePocketMoney::class)->handle($this->user, $this->holiday->refresh(), 500);
    $euroBalance = $this->holiday->refresh()->balance;

    ($this->switchTo)('HUF');

    $after = moneySnapshot($this->user);
    $newMovement = $this->user->pocketMovements()->latest('id')->firstOrFail();

    expect($coffee->refresh()->amount)->toBe(4_000)
        ->and($newMovement->amount)->toBe(2_000)
        ->and($this->holiday->refresh()->balance)->toBe(resolve(CurrencyConverter::class)->convert($euroBalance, Currency::EUR, Currency::HUF, '400', '1'));

    unset($after['transactions'][$coffee->id], $after['pocket_movements'][$newMovement->id]);
    $after['pockets'][$this->holiday->id]['balance'] = $original['pockets'][$this->holiday->id]['balance'];
    $after['periods'] = array_intersect_key($after['periods'], $original['periods']);

    expect($after)->toBe($original);
});

it('changes nothing and says so when MNB cannot be reached', function (): void {
    $before = moneySnapshot($this->user);
    $this->mnbDown = true;

    Livewire::test('pages::settings.budget')
        ->call('previewCurrency', 'EUR')
        ->assertHasErrors('currency')
        ->assertSee('The MNB exchange rate could not be fetched, so nothing has changed.')
        ->assertSet('pendingCurrencyChange', null)
        ->call('changeCurrency');

    expect(moneySnapshot($this->user))->toBe($before)
        ->and($this->user->settings()->refresh()->currency)->toBe(Currency::HUF)
        ->and(CurrencyConversion::query()->count())->toBe(0);
});

it('switches back without MNB, at the stored rate', function (): void {
    $original = moneySnapshot($this->user);
    ($this->switchTo)('EUR');
    $this->mnbDown = true;

    $page = Livewire::test('pages::settings.budget')->instance();
    $dialog = $page->previewCurrency('HUF', resolve(ChangeBaseCurrency::class));

    expect($dialog['title'])->toBe('Back to HUF?')
        ->and($dialog['highlight'])->toBe('The original amounts come back (switch: 2026-10-08, 1 € = 400.00 HUF)')
        ->and($dialog['body'])->toContain('converted back at the rate used at the switch, not at today\'s rate');

    $page->changeCurrency(resolve(ChangeBaseCurrency::class));

    expect(moneySnapshot($this->user))->toBe($original);
});

it('shows the example and the rate in Hungarian', function (): void {
    app()->setLocale('hu');
    $this->user->settings()->update(['income' => 150_000]);
    $this->eurRate = '393,45';

    $dialog = Livewire::test('pages::settings.budget')->instance()->previewCurrency('EUR', resolve(ChangeBaseCurrency::class));

    $plain = fn (string $text): string => str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

    expect($plain($dialog['highlight']))->toBe('MNB árfolyam 2026-10-08: 1 EUR = 393,45 Ft')
        ->and($plain($dialog['note']))->toBe('150 000 Ft → 381,24 EUR');
});

it('refuses a confirmation when the currency changed since the preview', function (): void {
    $stale = Livewire::test('pages::settings.budget')->instance();
    $stale->previewCurrency('EUR', resolve(ChangeBaseCurrency::class));
    ($this->switchTo)('EUR');
    $inEuro = moneySnapshot($this->user);

    $stale->changeCurrency(resolve(ChangeBaseCurrency::class));

    expect($stale->getErrorBag()->first('currency'))->toBe('The base currency changed in the meantime. Please try again.')
        ->and(moneySnapshot($this->user))->toBe($inEuro);
});

it('does not take the currency from the browser', function (): void {
    Livewire::test('pages::settings.budget')->set('currency', 'EUR');
})->throws(CannotUpdateLockedPropertyException::class);

it('forgets the conversions when the budget is reset', function (): void {
    ($this->switchTo)('EUR');

    resolve(ResetBudget::class)->handle($this->user);

    expect(CurrencyConversion::query()->count())->toBe(0)
        ->and(DB::table('currency_conversion_values')->count())->toBe(0);
});

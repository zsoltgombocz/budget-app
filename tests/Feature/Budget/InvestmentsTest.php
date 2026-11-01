<?php

use App\Actions\Investments\SaveInvestmentAccount;
use App\Actions\Investments\SetAccountValue;
use App\Actions\Investments\SyncInvestmentAccount;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\InvestmentProvider;
use App\Enums\Plan;
use App\Jobs\SyncInvestment;
use App\Models\Account;
use App\Models\AccountValue;
use App\Services\ExchangeRates\ExchangeRates;
use App\Services\ExchangeRates\ExchangeRateSource;
use App\Services\Investments\IbkrFlex;
use App\Services\Investments\IbkrFlexFailed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    Sleep::fake();
    $this->app->instance(ExchangeRateSource::class, new class implements ExchangeRateSource
    {
        public function latest(): ExchangeRates
        {
            return new ExchangeRates('2026-10-31', ['USD' => '360.00', 'EUR' => '390.00']);
        }
    });
});

function flexReport(string $rows, string $currency = 'USD'): string
{
    return '<FlexQueryResponse queryName="ms" type="AF"><FlexStatements count="1"><FlexStatement accountId="U1"><AccountInformation accountId="U1" currency="'.$currency.'" />'
        .'<EquitySummaryInBase>'.$rows.'</EquitySummaryInBase></FlexStatement></FlexStatements></FlexQueryResponse>';
}

it('reads the daily account value and base currency from an IBKR Flex report', function (): void {
    $statement = resolve(IbkrFlex::class)->parse(flexReport(
        '<EquitySummaryByReportDateInBase reportDate="20261030" total="10250.5" /><EquitySummaryByReportDateInBase reportDate="20261029" total="10000" />',
    ));

    expect($statement->currency)->toBe(Currency::USD)
        ->and($statement->values)->toBe(['2026-10-29' => 1_000_000, '2026-10-30' => 1_025_050]);
});

it('says what is missing from a Flex Query without the NAV section', function (): void {
    resolve(IbkrFlex::class)->parse('<FlexQueryResponse><FlexStatements /></FlexQueryResponse>');
})->throws(IbkrFlexFailed::class, 'Net Asset Value');

it('syncs an IBKR account: asks for the report, waits until it is ready, keeps every day', function (): void {
    Http::fake([
        '*/SendRequest*' => Http::response('<FlexStatementResponse><Status>Success</Status><ReferenceCode>42</ReferenceCode></FlexStatementResponse>'),
        '*/GetStatement*' => Http::sequence()
            ->push('<FlexStatementResponse><Status>Warn</Status><ErrorCode>1019</ErrorCode><ErrorMessage>Statement generation in progress.</ErrorMessage></FlexStatementResponse>')
            ->push(flexReport('<EquitySummaryByReportDateInBase reportDate="20261029" total="900" /><EquitySummaryByReportDateInBase reportDate="20261030" total="1000" />', 'EUR')),
    ]);
    $account = Account::factory()->investment()->for($this->user)->create(['provider' => InvestmentProvider::Ibkr, 'credentials' => ['token' => 'secret', 'query_id' => '123456'], 'sync_error' => 'old']);

    expect(resolve(SyncInvestmentAccount::class)->handle($account))->toBeTrue();

    $account->refresh();
    expect($account->currency)->toBe(Currency::EUR)
        ->and($account->value)->toBe(100_000)
        ->and($account->valued_on?->toDateString())->toBe('2026-10-30')
        ->and($account->sync_error)->toBeNull()
        ->and($account->values()->count())->toBe(2);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'SendRequest') && $request['t'] === 'secret' && $request['q'] === '123456');
});

it('keeps the reason on the account when IBKR refuses', function (): void {
    Http::fake(['*/SendRequest*' => Http::response('<FlexStatementResponse><Status>Fail</Status><ErrorCode>1012</ErrorCode><ErrorMessage>Token has expired.</ErrorMessage></FlexStatementResponse>')]);
    $account = Account::factory()->investment()->for($this->user)->create(['provider' => InvestmentProvider::Ibkr, 'credentials' => ['token' => 'old', 'query_id' => '1']]);

    expect(resolve(SyncInvestmentAccount::class)->handle($account))->toBeFalse()
        ->and($account->refresh()->sync_error)->toContain('Token has expired.');
});

it('adds a manual account with its value, shows it in its currency and in forints, and keeps the token encrypted', function (): void {
    Livewire::test('investments')
        ->call('save', ['name' => 'ActivTrades', 'currency' => 'USD', 'provider' => 'manual', 'amounts' => ['value' => '1250']])
        ->assertSee('ActivTrades')
        ->assertSee(money_number(125_000, Currency::USD))
        ->assertSee(money(450_000));

    $account = Account::query()->where('name', 'ActivTrades')->sole();
    expect($account->type)->toBe(AccountType::Investment)->and($account->value)->toBe(125_000);

    Queue::fake();
    Livewire::test('investments')->call('save', ['name' => 'IBKR', 'currency' => 'USD', 'provider' => 'ibkr', 'token' => 'tok-123', 'queryId' => '987654', 'amounts' => ['value' => '']]);

    $ibkr = Account::query()->where('name', 'IBKR')->sole();
    expect($ibkr->credentials)->toBe(['token' => 'tok-123', 'query_id' => '987654'])
        ->and(DB::table('accounts')->where('id', $ibkr->id)->value('credentials'))->not->toContain('tok-123');
    Queue::assertPushed(SyncInvestment::class, fn (SyncInvestment $job): bool => $job->accountId === $ibkr->id);

    $data = Livewire::test('investments')->instance()->accountData($ibkr->id);
    expect($data['token'])->toBe('')->and($data['hasToken'])->toBeTrue();
});

it('asks for the IBKR token and query ID, and keeps the saved token when left empty', function (): void {
    Queue::fake();
    $result = Livewire::test('investments')->instance()->save(['name' => 'IBKR', 'currency' => 'USD', 'provider' => 'ibkr', 'token' => '', 'queryId' => 'abc', 'amounts' => ['value' => '']], resolve(SaveInvestmentAccount::class), resolve(SetAccountValue::class));

    expect($result['errors'])->toHaveKeys(['token', 'queryId']);

    $account = Account::factory()->investment()->for($this->user)->create(['provider' => InvestmentProvider::Ibkr, 'credentials' => ['token' => 'kept', 'query_id' => '1']]);
    Livewire::test('investments')->call('save', ['id' => $account->id, 'name' => 'IBKR', 'currency' => 'USD', 'provider' => 'ibkr', 'token' => '', 'queryId' => '222', 'amounts' => ['value' => '']]);

    expect($account->refresh()->credentials)->toBe(['token' => 'kept', 'query_id' => '222']);
});

it('draws the trend once there are two values, and shows the tab on My money', function (): void {
    $account = Account::factory()->investment()->for($this->user)->create(['value' => 110_000, 'valued_on' => '2026-11-01']);
    AccountValue::factory()->for($this->user)->for($account)->create(['date' => '2026-10-01', 'value' => 100_000]);
    AccountValue::factory()->for($this->user)->for($account)->create(['date' => '2026-11-01', 'value' => 110_000]);

    Livewire::test('investments')->assertSee('data-test="investment-trend"', false)->assertSee('+10,0%');
    $this->get(route('pockets', ['ful' => 'befektetesek']))->assertOk()->assertSee('data-test="tab-investments"', false)->assertSee(money_number(396_000));
});

it('keeps investments to Plusz and to the owner', function (): void {
    $other = Account::factory()->investment()->create();

    expect(fn () => Livewire::test('investments')->instance()->accountData($other->id))->toThrow(ModelNotFoundException::class);

    $this->user->forceFill(['plan' => Plan::Basic])->save();
    Livewire::test('investments')->call('accountData', null)->assertForbidden();
});

it('syncs the IBKR accounts of Plusz users every morning', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('<FlexStatementResponse><Status>Fail</Status><ErrorMessage>nope</ErrorMessage></FlexStatementResponse>')]);
    $account = Account::factory()->investment()->for($this->user)->create(['provider' => InvestmentProvider::Ibkr, 'credentials' => ['token' => 't', 'query_id' => '1']]);

    $this->artisan('investments:sync')->assertSuccessful();

    expect($account->refresh()->sync_error)->toContain('nope');
});

it('keeps one value a day when it is typed in again', function (): void {
    $account = Account::factory()->investment()->for($this->user)->create();
    $set = resolve(SetAccountValue::class);

    $set->handle($account, 100_000);
    $set->handle($account, 131_000);

    expect($account->values()->count())->toBe(1)
        ->and($account->values()->sole()->value)->toBe(131_000)
        ->and($account->refresh()->value)->toBe(131_000);
});

it('leaves the investment account picked at setup off the tab until it is tracked', function (): void {
    $target = Account::factory()->investment()->for($this->user)->create(['name' => 'Closing target']);

    Livewire::test('investments')->assertDontSee('Closing target')->assertDontSee('data-test="investment-'.$target->id.'"', false)
        ->assertSee('data-test="investments-empty"', false)->assertSee('You have no investment account yet.');
});

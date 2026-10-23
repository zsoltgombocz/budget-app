<?php

use App\Enums\PeriodMode;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-15'));
    $category = Category::factory()->for($this->user)->create();
    foreach (range(9, 15) as $day) {
        Transaction::factory()->for($this->user)->for($this->period)->for($category)->create(['occurred_on' => sprintf('2026-10-%02d', $day)]);
    }
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

it('shows the streak, the acorns and celebrates new milestones once', function (): void {
    Livewire::test('pages::companion')
        ->assertSee('7 nap')
        ->assertSee('Leghosszabb: 7')
        ->assertSee((7 * 5 + 10 + 30).' makk')
        ->assertSet('celebrate', ['first_entry', 'week'])
        ->assertSee('Egy hét');

    Livewire::test('pages::companion')->assertSet('celebrate', []);
});

it('creates a goal that feeds from a monthly savings line', function (): void {
    Livewire::test('pages::companion')
        ->call('createGoal', ['name' => 'Nyaralás', 'target' => '300000', 'month' => '2027-06', 'starting' => '100000', 'monthly' => '25000'])
        ->assertReturned(['ok' => true, 'errors' => []])
        ->assertSee('Nyaralás')
        ->assertSee('Havi '.money(25_000));

    expect(Pocket::query()->sole()->balance)->toBe(100_000)
        ->and($this->user->budgetLines()->sole()->amount)->toBe(25_000);
});

it('sets what is on a goal and archives it with an undo', function (): void {
    $page = Livewire::test('pages::companion')->call('createGoal', ['name' => 'Autó', 'target' => '', 'month' => '', 'starting' => '', 'monthly' => '']);
    $goal = Pocket::query()->sole();

    $page->call('updateGoal', $goal->id, ['name' => 'Autó', 'target' => '1000000', 'month' => '', 'balance' => '50000'])
        ->assertReturned(['ok' => true, 'errors' => []]);

    expect($goal->fresh()->balance)->toBe(50_000)->and($goal->fresh()->target_amount)->toBe(1_000_000);

    $page->call('archiveGoal', $goal->id)->assertDispatched('app-toast');

    expect(Pocket::query()->count())->toBe(0);

    $page->call('restoreGoal', $goal->id);

    expect(Pocket::query()->count())->toBe(1);
});

it('takes the weekly challenge', function (): void {
    Livewire::test('pages::companion')
        ->assertSee('Rögzíts a hét 5 napján.')
        ->call('acceptChallenge')
        ->assertSee('4/5')
        ->assertSee('még 3 nap · vasárnapig');
});

it('buys glasses for acorns and puts Morzsa in them', function (): void {
    Livewire::test('pages::companion')
        ->call('buy', 'glasses')
        ->assertDispatched('app-toast')
        ->assertSee('Felvéve');

    expect($this->user->items()->sole()->item)->toBe('glasses');
});

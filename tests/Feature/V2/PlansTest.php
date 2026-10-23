<?php

use App\Enums\PeriodMode;
use App\Enums\Plan;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Admin;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\User;
use App\Models\WatchedCategory;
use App\Services\Plans;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00'));
    $this->user = User::factory()->create(['created_at' => now()]);
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    app()->setLocale('hu');
});

it('gives the trial (Alap) for 30 days, then Alap', function (): void {
    expect($this->user->currentPlan())->toBe(Plan::Trial)
        ->and($this->user->hasPlusz())->toBeFalse();

    $this->travel(31)->days();

    expect($this->user->currentPlan())->toBe(Plan::Basic);
});

it('lets an admin set Plusz until a day, logged, then it ends', function (): void {
    $admin = Admin::factory()->create();
    resolve(Plans::class)->set($this->user, Plan::Plus, CarbonImmutable::parse('2026-11-30'), $admin, 'owner');

    expect($this->user->fresh()->hasPlusz())->toBeTrue()
        ->and($this->user->planChanges()->sole()->admin_id)->toBe($admin->id);

    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:00'));
    expect($this->user->fresh()->hasPlusz())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00'));
    expect($this->user->fresh()->currentPlan())->toBe(Plan::Basic);

    resolve(Plans::class)->set($this->user, null);
    expect($this->user->fresh()->plan)->toBeNull()
        ->and($this->user->planChanges()->count())->toBe(2);
});

it('starts the 14-day Plusz offer once, only after the trial', function (): void {
    $plans = resolve(Plans::class);
    expect($plans->canStartOffer($this->user))->toBeFalse();

    $this->travel(31)->days();
    $plans->startOffer($this->user);

    expect($this->user->fresh()->currentPlan())->toBe(Plan::PlusTrial)
        ->and($plans->canStartOffer($this->user->fresh()))->toBeFalse();

    $this->travel(15)->days();
    expect($this->user->fresh()->currentPlan())->toBe(Plan::Basic);

    $plans->startOffer($this->user->fresh());
})->throws(ValidationException::class);

it('sets a plan from the admin panel', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->create(), 'admin');

    Livewire::test(ManageUsers::class)
        ->callTableAction('plan', $this->user, data: ['plan' => 'plus', 'plan_until' => null, 'note' => 'owner'])
        ->assertHasNoTableActionErrors();

    expect($this->user->fresh()->plan)->toBe(Plan::Plus)
        ->and($this->user->fresh()->plan_until)->toBeNull();

    Livewire::test(ManageUsers::class)->callTableAction('plan', $this->user, data: ['plan' => 'auto', 'plan_until' => null, 'note' => null]);

    expect($this->user->fresh()->plan)->toBeNull();
});

it('sets a plan from the command line', function (): void {
    $this->artisan('budget:plan', ['email' => $this->user->email, 'plan' => 'plus'])->assertSuccessful();
    expect($this->user->fresh()->plan)->toBe(Plan::Plus);

    $this->artisan('budget:plan', ['email' => $this->user->email, 'plan' => 'trial'])->assertFailed();
});

it('shows two watched categories on Alap: the kept ones, otherwise the oldest', function (): void {
    $watches = collect(['Bolt', 'Étterem', 'Szórakozás'])->map(fn (string $name): WatchedCategory => WatchedCategory::factory()->for($this->user)
        ->for(Category::factory()->for($this->user)->create(['name' => $name]))->create());

    expect($this->user->visibleWatchedCategories()->pluck('id')->all())->toBe([$watches[0]->id, $watches[1]->id]);

    $watches[2]->update(['kept_on_basic' => true]);
    expect($this->user->visibleWatchedCategories()->pluck('id')->all())->toBe([$watches[0]->id, $watches[2]->id]);

    $this->user->forceFill(['plan' => Plan::Plus])->save();
    expect($this->user->visibleWatchedCategories())->toHaveCount(3);
});

it('shows the plan, Plusz and the prices on the subscription page, and starts the offer', function (): void {
    $this->actingAs($this->user);

    $this->get(route('subscription'))->assertOk()
        ->assertSee('Próba')
        ->assertSee('Mi van a Pluszban?')
        ->assertSee('A fizetés hamarosan indul')
        ->assertDontSee('Próbáld ki a Pluszt 14 napig ingyen');

    $this->travel(31)->days();

    Livewire::test('pages::settings.subscription')
        ->assertSee('Próbáld ki a Pluszt 14 napig ingyen')
        ->call('startOffer')
        ->assertDispatched('app-toast');

    expect($this->user->fresh()->currentPlan())->toBe(Plan::PlusTrial);
});

it('lets a Plusz user pick at most two watched categories to keep', function (): void {
    $this->user->forceFill(['plan' => Plan::Plus])->save();
    $this->actingAs($this->user);
    $watches = collect(['Bolt', 'Étterem', 'Szórakozás'])->map(fn (string $name): WatchedCategory => WatchedCategory::factory()->for($this->user)
        ->for(Category::factory()->for($this->user)->create(['name' => $name]))->create());

    $page = Livewire::test('pages::settings.subscription')
        ->assertSee('Melyik 2 figyelt kategória maradjon látható?')
        ->call('keep', $watches[0]->id)
        ->call('keep', $watches[1]->id)
        ->call('keep', $watches[2]->id)
        ->assertDispatched('app-toast');

    expect($this->user->watchedCategories()->where('kept_on_basic', true)->pluck('id')->all())->toBe([$watches[0]->id, $watches[1]->id]);

    $page->call('keep', $watches[0]->id);
    expect($watches[0]->fresh()->kept_on_basic)->toBeFalse();
});

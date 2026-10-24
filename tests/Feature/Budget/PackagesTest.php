<?php

use App\Enums\PeriodMode;
use App\Enums\Plan;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Admin;
use App\Models\BudgetSetting;
use App\Models\User;
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

<?php

use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\Plan;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Admin;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\User;
use App\Services\Data\PlanLine;
use App\Services\PeriodService;
use App\Services\Plans;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00'));
    $this->user = User::factory()->create(['created_at' => now()]);
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    app()->setLocale('hu');
});

it('gives Plusz for the first 14 days, then the free Alap', function (): void {
    expect($this->user->currentPlan())->toBe(Plan::PlusTrial)
        ->and($this->user->hasPlusz())->toBeTrue();

    $this->travel(15)->days();

    expect($this->user->currentPlan())->toBe(Plan::Basic)
        ->and($this->user->hasPlusz())->toBeFalse();
});

it('gives Plusz with a paid subscription, also on its paid time after cancelling', function (): void {
    $this->travel(30)->days();
    $subscription = $this->user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active', 'stripe_price' => 'price_1', 'quantity' => 1]);

    expect($this->user->fresh()->currentPlan())->toBe(Plan::Plus);

    $subscription->update(['ends_at' => now()->addDays(10)]);
    expect($this->user->fresh()->currentPlan())->toBe(Plan::Plus);

    $this->travel(11)->days();
    expect($this->user->fresh()->currentPlan())->toBe(Plan::Basic);
});

it('does not count putting money aside in the month on Alap, but keeps the lines', function (): void {
    $this->travel(15)->days();

    expect(resolve(Plans::class)->lockedLineTypes($this->user))->toBe([LineType::Transfer, LineType::Sinking]);
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

it('keeps pockets and loans on Alap but locks them', function (): void {
    $this->travel(15)->days();
    $this->actingAs($this->user);
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 50_000]);

    $this->get(route('pockets'))->assertOk()->assertSee('data-test="plusz-lock"', false)->assertSee('Holiday');

    Livewire::test('pages::pockets')->call('movePocketMoney', $pocket->id, 1, '1000', null)->assertForbidden();

    expect($pocket->fresh()->balance)->toBe(50_000);
});

it('leaves the savings lines out of the month on Alap', function (): void {
    $category = Category::factory()->for($this->user)->create(['type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 30_000]);
    $period = resolve(PeriodService::class)->current($this->user);

    $planned = fn (): int => array_sum(array_map(fn (PlanLine $line): int => $line->planned(), resolve(PlanService::class)->linesFor($period)));
    $withPlusz = $planned();

    $this->travel(15)->days();

    expect($planned())->toBe($withPlusz - 30_000);
});

it('keeps Plusz for a paid subscription even when an admin set Alap', function (): void {
    $this->travel(30)->days();
    $this->user->forceFill(['plan' => Plan::Basic])->save();
    $this->user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_2', 'stripe_status' => 'active', 'stripe_price' => 'price_1', 'quantity' => 1]);

    expect($this->user->fresh()->currentPlan())->toBe(Plan::Plus);
});

it('gives users from before paid Plusz their trial from the release', function (): void {
    $this->travel(60)->days();
    $this->user->forceFill(['plus_offer_until' => now()->addDays(14)])->save();

    expect($this->user->fresh()->currentPlan())->toBe(Plan::PlusTrial);

    $this->travel(15)->days();
    expect($this->user->fresh()->currentPlan())->toBe(Plan::Basic);
});

it('removes the subscription rows, push subscriptions and sessions with the account', function (): void {
    $subscription = $this->user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_3', 'stripe_status' => 'canceled', 'stripe_price' => 'price_1', 'quantity' => 1]);
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()]);

    $this->user->delete();

    expect(Subscription::query()->whereKey($subscription->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('tells on Today that savings do not count on Alap, and closes with the leftover staying', function (): void {
    $category = Category::factory()->for($this->user)->create(['type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 30_000]);
    $this->travel(15)->days();
    $this->actingAs($this->user);

    $this->get(route('dashboard'))->assertSee('data-test="locked-savings"', false)->assertSee(money(30_000));
});

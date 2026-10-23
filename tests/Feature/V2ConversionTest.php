<?php

use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\User;
use App\Models\WatchedCategory;

function v2Line(User $user, LineType $type, string $name, int $amount, array $attributes = []): BudgetLine
{
    $category = Category::factory()->for($user)->create(['name' => $name, 'type' => $type]);

    return BudgetLine::factory()->for($user)->create(['category_id' => $category->id, 'amount' => $amount, ...$attributes]);
}

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'owner@example.com']);
    $this->rent = v2Line($this->user, LineType::Fixed, 'Rent', 150_000, ['due_day' => 10]);
    $this->shared = v2Line($this->user, LineType::Transfer, 'Shared account', 25_000);
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 100_000]);
    $this->savings = v2Line($this->user, LineType::Sinking, 'Holiday', 45_000, ['pocket_id' => $pocket->id, 'due_day' => 12]);
    $this->groceries = v2Line($this->user, LineType::Variable, 'Groceries', 60_000, ['calc_mode' => CalcMode::Avg, 'amount_avg' => 55_000]);
    $this->user->settings()->update(['reserve_pct' => 50]);
});

it('only reports on a dry run', function (): void {
    $this->artisan('budget:convert-v2', ['email' => 'owner@example.com'])
        ->expectsOutputToContain('Transfer "Shared account" (25000) becomes a fixed expense paid by hand.')
        ->expectsOutputToContain('Budget "Groceries" (55000) leaves the plan and becomes a watched category.')
        ->expectsOutputToContain('4 change(s) would be made.')
        ->assertSuccessful();

    expect($this->groceries->fresh())->not->toBeNull()
        ->and($this->shared->category->fresh()->type)->toBe(LineType::Transfer)
        ->and(WatchedCategory::query()->count())->toBe(0)
        ->and($this->user->settings()->fresh()->reserve_pct)->toBe(50);
});

it('moves the plan to the 2.0 model with --force', function (): void {
    $this->artisan('budget:convert-v2', ['email' => 'owner@example.com', '--force' => true])
        ->expectsOutputToContain('4 change(s) made.')
        ->assertSuccessful();

    expect($this->shared->category->fresh()->type)->toBe(LineType::Fixed)
        ->and($this->shared->fresh()->manual_pay)->toBeTrue()
        ->and($this->savings->fresh()->manual_pay)->toBeTrue()
        ->and($this->savings->fresh()->pocket->balance)->toBe(100_000)
        ->and($this->rent->fresh()->manual_pay)->toBeFalse()
        ->and($this->groceries->fresh())->toBeNull()
        ->and(WatchedCategory::query()->sole()->only(['category_id', 'amount']))->toBe(['category_id' => $this->groceries->category_id, 'amount' => 55_000])
        ->and($this->user->settings()->fresh()->reserve_pct)->toBe(100);
});

it('has nothing to do the second time', function (): void {
    $this->artisan('budget:convert-v2', ['email' => 'owner@example.com', '--force' => true]);

    $this->artisan('budget:convert-v2', ['email' => 'owner@example.com'])
        ->expectsOutputToContain('Savings "Holiday" (45000) stays a savings line')
        ->expectsOutputToContain('1 change(s) would be made.');
});

it('fails for an unknown user', function (): void {
    $this->artisan('budget:convert-v2', ['email' => 'nobody@example.com'])->assertFailed();
});

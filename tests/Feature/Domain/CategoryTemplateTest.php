<?php

use App\Actions\Budget\ApplyCategoryTemplate;
use App\Enums\LineType;
use App\Models\CategoryTemplate;
use App\Models\User;
use Database\Seeders\CategoryTemplateSeeder;
use Database\Seeders\DemoSeeder;

beforeEach(fn () => $this->seed(CategoryTemplateSeeder::class));

it('seeds the four onboarding templates', function (): void {
    expect(CategoryTemplate::query()->orderBy('sort')->pluck('key')->all())
        ->toBe(['basic', 'with_loan', 'couple', 'empty']);
});

it('applies the loan template with pockets and a loan', function (): void {
    $user = User::factory()->create();

    resolve(ApplyCategoryTemplate::class)->handle($user, CategoryTemplate::query()->where('key', 'with_loan')->firstOrFail());

    $prepayPocket = $user->pockets()->whereNotNull('prepay_step')->firstOrFail();

    expect($user->categories()->count())->toBe(10)
        ->and($user->budgetLines()->count())->toBe(10)
        ->and($user->pockets()->where('is_reserve', true)->count())->toBe(1)
        ->and($user->loans()->count())->toBe(1)
        ->and($prepayPocket->prepay_step)->toBe(500_000)
        ->and($prepayPocket->loan_id)->toBe($user->loans()->value('id'))
        ->and($user->categories()->where('type', LineType::Variable)->where('is_quick_entry', true)->count())->toBe(4);
});

it('applies the empty template without creating anything', function (): void {
    $user = User::factory()->create();

    resolve(ApplyCategoryTemplate::class)->handle($user, CategoryTemplate::query()->where('key', 'empty')->firstOrFail());

    expect($user->categories()->count())->toBe(0);
});

it('seeds a demo user with a plan and spending', function (): void {
    $this->seed(DemoSeeder::class);

    $user = User::query()->where('email', DemoSeeder::EMAIL)->firstOrFail();

    expect($user->settings()->isOnboarded())->toBeTrue()
        ->and($user->periods()->count())->toBe(3)
        ->and($user->transactions()->count())->toBeGreaterThan(0)
        ->and($user->budgetLines()->where('amount', '>', 0)->count())->toBe(10);
});

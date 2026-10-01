<?php

use App\Models\Category;
use App\Models\User;

it('only exposes the authenticated user\'s records', function (): void {
    [$alice, $bob] = User::factory()->count(2)->create();
    Category::factory()->for($alice)->create(['name' => 'Alice']);
    Category::factory()->for($bob)->create(['name' => 'Bob']);

    $this->actingAs($alice);

    expect(Category::query()->pluck('name')->all())->toBe(['Alice']);
});

it('fills the owner on create', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $category = Category::query()->create(['name' => 'Fuel', 'type' => 'variable']);

    expect($category->user_id)->toBe($user->id);
});

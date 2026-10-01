<?php

use App\Models\User;
use Database\Seeders\CategoryTemplateSeeder;

it('disables double-tap zoom and focus zoom on touch devices', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->iPhone14Pro();

    $result = $page->script(<<<'JS'
        () => ({
            html: getComputedStyle(document.documentElement).touchAction,
            key: getComputedStyle(document.querySelector('[data-test="entry-sheet"] button')).touchAction,
            input: parseFloat(getComputedStyle(document.querySelector('[data-test="entry-sheet"] input[type="text"]')).fontSize),
        })
    JS);

    expect($result['html'])->toBe('manipulation')
        ->and($result['key'])->toBe('manipulation')
        ->and($result['input'])->toBeGreaterThanOrEqual(16.0);
});

it('uses an opaque status bar on iPhone so iOS does not blur the page header', function (): void {
    $this->actingAs(User::factory()->create());
    $this->seed(CategoryTemplateSeeder::class);

    $style = visit(route('onboarding'))->on()->iPhone14Pro()
        ->script("() => document.querySelector('meta[name=apple-mobile-web-app-status-bar-style]').content");

    expect($style)->toBe('black');
});

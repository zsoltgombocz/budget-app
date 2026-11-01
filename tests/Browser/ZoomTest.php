<?php

use App\Models\User;
use Database\Seeders\CategoryTemplateSeeder;

it('does not zoom: no pinch, no double tap, no focus zoom on touch devices', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->iPhone14Pro();

    $result = $page->script(<<<'JS'
        () => ({
            html: getComputedStyle(document.documentElement).touchAction,
            key: getComputedStyle(document.querySelector('[data-test="entry-sheet"] button')).touchAction,
            input: parseFloat(getComputedStyle(document.querySelector('[data-test="entry-sheet"] input[type="text"]')).fontSize),
            viewport: document.querySelector('meta[name=viewport]').content,
        })
    JS);

    expect($result['html'])->toBe('pan-x pan-y')
        ->and($result['viewport'])->toContain('user-scalable=no')
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

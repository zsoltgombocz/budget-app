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

it('keeps the onboarding header below the status bar when installed on iPhone', function (): void {
    $this->actingAs(User::factory()->create());
    $this->seed(CategoryTemplateSeeder::class);

    $page = visit(route('onboarding'))->on()->iPhone14Pro();

    $top = $page->script(<<<'JS'
        () => {
            document.documentElement.classList.add('ios-standalone')
            return document.querySelector('main').getBoundingClientRect().top + parseFloat(getComputedStyle(document.querySelector('main')).paddingTop)
        }
    JS);

    expect($top)->toBeGreaterThanOrEqual(50.0);
});

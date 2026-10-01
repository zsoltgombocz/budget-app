<?php

use App\Support\Changelog;

it('has a line in every app language for every change', function (): void {
    foreach (Changelog::all() as $entry) {
        expect($entry['version'])->toMatch('/^\d+\.\d+\.\d+$/');

        foreach ($entry['changes'] as $change) {
            expect($change)->toHaveKeys(['hu', 'en'])
                ->and($change['hu'])->not->toBeEmpty()
                ->and($change['en'])->not->toBeEmpty();
        }
    }
});

it('lists versions newest first and exposes the current one', function (): void {
    $versions = array_column(Changelog::all(), 'version');
    $sorted = $versions;
    usort($sorted, fn (string $a, string $b): int => version_compare($b, $a));

    expect($versions)->toBe($sorted)
        ->and(config('app.version'))->toBe($versions[0]);
});

it('shows the changelog in the user\'s language', function (): void {
    $user = onboardedUser(['locale' => 'hu']);
    $this->actingAs($user);

    $this->get(route('changelog'))
        ->assertOk()
        ->assertSee(Changelog::changes(Changelog::all()[0], 'hu')[0]);
});

it('shows the version in settings and the new version sheet in the app', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('settings'))->assertSee(route('changelog'))->assertSee(config('app.version'));
    $this->get(route('dashboard'))->assertSee('data-test="new-version"', false);
});

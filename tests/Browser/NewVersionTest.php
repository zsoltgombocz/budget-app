<?php

it('pops up the new version once and remembers it', function (): void {
    $user = onboardedUser();
    $user->forceFill(['created_at' => now()->subDays(3)])->save();
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile();

    $page->waitForText('New version is here');

    $seen = $page->script(<<<'JS'
        () => {
            document.querySelector('[data-test="new-version-close"]').click()
            return localStorage.getItem('seen-version')
        }
    JS);

    expect($seen)->toBe(config('app.version'));
});

it('does not bother brand-new accounts', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('dashboard'))->on()->mobile()->wait(1)->assertDontSee('New version is here');
});

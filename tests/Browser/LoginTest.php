<?php

use App\Models\User;

it('shows the email first and the passkey below without overflowing a 320 px screen', function (): void {
    $page = visit(route('login'))->on()->mobile()->resize(320, 568)
        ->assertVisible('[data-test="magic-link-email"]')
        ->click('[data-test="magic-link-send"]')
        ->waitForText('Enter your email address.')
        ->assertNoJavaScriptErrors();

    expect($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
});

it('keeps the code step on a 320 px screen, its two quiet actions on one row', function (): void {
    User::factory()->create(['email' => 'anna@example.com']);

    $page = visit(route('login'))->on()->mobile()->resize(320, 568);

    $result = $page->script('async () => {
        const form = Livewire.all().find(component => component.name === "auth.magic-link-form").$wire
        form.email = "anna@example.com"
        await form.send()
        await new Promise(resolve => setTimeout(resolve, 300))
        const notReceived = document.querySelector("[data-test=magic-link-not-received]").getBoundingClientRect()
        const again = document.querySelector("[data-test=magic-link-again]").getBoundingClientRect()
        return {
            noSideScroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
            oneRow: Math.abs(notReceived.top - again.top) < 1,
        }
    }');

    expect($result)->toBe(['noSideScroll' => true, 'oneRow' => true]);
});

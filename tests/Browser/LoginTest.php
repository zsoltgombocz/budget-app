<?php

it('shows the email first and the passkey below without overflowing a 320 px screen', function (): void {
    $page = visit(route('login'))->on()->mobile()->resize(320, 568)
        ->assertVisible('[data-test="magic-link-email"]')
        ->click('[data-test="magic-link-send"]')
        ->waitForText('Enter your email address.')
        ->assertNoJavaScriptErrors();

    expect($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
});

<?php

use App\Http\Middleware\DevGate;

beforeEach(function (): void {
    config(['budget.dev_gate_password' => 'secret-dev']);
});

it('sends visitors without the cookie to the password page and asks robots to stay away', function (): void {
    $this->get(route('login'))
        ->assertRedirect(route('dev-gate'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get(route('dev-gate'))->assertOk()->assertSee('data-test="dev-gate-password"', false);
});

it('rejects a wrong password', function (): void {
    $this->from(route('dev-gate'))
        ->post(route('dev-gate.check'), ['password' => 'nope'])
        ->assertRedirect(route('dev-gate'))
        ->assertSessionHasErrors('password')
        ->assertCookieMissing(DevGate::COOKIE);
});

it('remembers the right password in a long-lived cookie', function (): void {
    $this->post(route('dev-gate.check'), ['password' => 'secret-dev'])
        ->assertRedirect(route('dashboard'))
        ->assertCookie(DevGate::COOKIE, DevGate::cookieValue('secret-dev'));

    $this->withCookie(DevGate::COOKIE, DevGate::cookieValue('secret-dev'))
        ->get(route('login'))
        ->assertOk();
});

it('stops accepting the cookie when the password changes', function (): void {
    $cookie = DevGate::cookieValue('secret-dev');
    config(['budget.dev_gate_password' => 'new-secret']);

    $this->withCookie(DevGate::COOKIE, $cookie)->get(route('login'))->assertRedirect(route('dev-gate'));
});

it('keeps the health check open for the deploy script', function (): void {
    $this->get('/up')->assertOk();
});

it('is off when no password is set', function (): void {
    config(['budget.dev_gate_password' => null]);

    $this->get(route('login'))->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

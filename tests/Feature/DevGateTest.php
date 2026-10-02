<?php

use App\Http\Middleware\DevGate;
use Illuminate\Http\Request;

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

it('leaves the admin panel and the monitoring to the admin sign-in', function (): void {
    $this->get('/admin/login')->assertOk();
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->get('/pulse')->assertRedirect(route('filament.admin.auth.login'));
});

it('lets the Livewire requests of the admin pages through, but not those of the app', function (): void {
    $fromAdmin = Request::create('/livewire/update', 'POST', server: ['HTTP_X_LIVEWIRE' => '1', 'HTTP_REFERER' => 'https://dev.example.com/admin/login']);
    $fromApp = Request::create('/livewire/update', 'POST', server: ['HTTP_X_LIVEWIRE' => '1', 'HTTP_REFERER' => 'https://dev.example.com/login']);
    $sneaky = Request::create('/livewire/update', 'POST', server: ['HTTP_X_LIVEWIRE' => '1', 'HTTP_REFERER' => 'https://dev.example.com/administrator']);

    expect(DevGate::isAdminRequest($fromAdmin))->toBeTrue()
        ->and(DevGate::isAdminRequest($fromApp))->toBeFalse()
        ->and(DevGate::isAdminRequest($sneaky))->toBeFalse();
});

it('keeps the health check open for the deploy script', function (): void {
    $this->get('/up')->assertOk();
});

it('is off when no password is set', function (): void {
    config(['budget.dev_gate_password' => null]);

    $this->get(route('login'))->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

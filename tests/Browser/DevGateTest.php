<?php

use App\Models\Admin;
use Illuminate\Support\Facades\Notification;

it('turns the dev password button busy on the first tap so it cannot be sent twice', function (): void {
    config(['budget.dev_gate_password' => 'secret-dev']);

    $page = visit(route('dev-gate'))->on()->mobile();

    $state = $page->script(<<<'JS'
        () => {
            const button = document.querySelector('[data-test="dev-gate-submit"]')
            document.querySelector('[data-test="dev-gate-password"]').value = 'secret-dev'
            const form = button.closest('form')
            form.addEventListener('submit', event => event.preventDefault())
            button.click()
            button.click()
            return { disabled: button.disabled, label: button.textContent.trim() }
        }
    JS);

    expect($state)->toBe(['disabled' => true, 'label' => 'Checking…']);
});

it('lets an admin sign in on the dev stack without the dev password', function (): void {
    config(['budget.dev_gate_password' => 'secret-dev']);
    Notification::fake();
    Admin::factory()->create(['email' => 'boss@example.com']);

    visit('/admin/login')
        ->type('#form\.email', 'boss@example.com')
        ->click('button[type=submit]')
        ->assertSee('boss@example.com')
        ->assertPathIs('/admin/login');
});

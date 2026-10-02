<?php

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

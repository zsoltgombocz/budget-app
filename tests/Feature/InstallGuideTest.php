<?php

it('serves the install guide without logging in', function (): void {
    $this->get(route('install'))
        ->assertOk()
        ->assertSee('data-test="install-guide"', false)
        ->assertSee('rel="manifest"', false);
});

it('links the guide from the install card', function (): void {
    $this->get(route('login'))->assertSee(route('install'));
});

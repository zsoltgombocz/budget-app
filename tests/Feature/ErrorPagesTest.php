<?php

it('shows the error pages in Hungarian, without the build', function (): void {
    app()->setLocale('hu');

    $this->get('/nincs-ilyen-oldal')->assertNotFound()
        ->assertSee('Ezt az oldalt nem találom')
        ->assertSee('Vissza az appba')
        ->assertDontSee('/build/', false);
});

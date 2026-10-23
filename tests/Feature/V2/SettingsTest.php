<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Services\PeriodService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser(['locale' => 'hu']);
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

it('lists the 2.0 settings', function (): void {
    $this->get(route('settings'))->assertOk()
        ->assertSeeInOrder(['A hónapod', 'Morzsa', 'Értesítések', 'Megjelenés', 'Adataim letöltése', 'Hogy működik?']);
});

it('sets Morzsa quiet or off, and off renames the tab and hides Morzsa on Today', function (): void {
    Livewire::test('pages::settings.companion')->set('mode', 'off')->assertDispatched('app-toast');

    expect($this->user->settings()->fresh()->companion_mode)->toBe('off');

    $this->get(route('dashboard'))->assertOk()->assertSee('Haladás')->assertSee('data-test="month-days"', false)->assertDontSee('data-test="companion"', false);
});

it('refuses an unknown presence', function (): void {
    Livewire::test('pages::settings.companion')->set('mode', 'loud')->assertHasErrors('mode');
});

it('explains how it works in five cards', function (): void {
    $this->get(route('help'))->assertOk()->assertSee('Még erre a hónapra')->assertSee('5 / 5');
});

it('downloads the user\'s spending as CSV, nobody else\'s', function (): void {
    $period = resolve(PeriodService::class)->current($this->user);
    Transaction::factory()->for($this->user)->for($period)->for(Category::query()->where('name', 'Fuel')->firstOrFail())->create(['amount' => 12_500, 'note' => 'Shell']);
    Transaction::factory()->create(['note' => 'Not mine']);

    $csv = $this->get(route('export'))->assertOk()->assertDownload()->streamedContent();

    expect($csv)->toContain('Költés;Fuel;12500;HUF;Shell')->not->toContain('Not mine');
});

<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('kezdes', 'pages::onboarding')->name('onboarding');

    Route::middleware('onboarded')->group(function (): void {
        Route::livewire('ma', 'pages::today')->name('dashboard');
        Route::livewire('rogzites', 'pages::entry')->name('entry');
        Route::livewire('terv', 'pages::plan')->name('plan');
        Route::livewire('honap', 'pages::month')->name('month');
        Route::livewire('perselyek', 'pages::pockets')->name('pockets');
    });
});

require __DIR__.'/settings.php';

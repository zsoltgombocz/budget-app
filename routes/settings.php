<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('settings', 'pages::settings.index')->name('settings');
    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
    Route::livewire('settings/security', 'pages::settings.security')->name('security.edit');

    Route::middleware('onboarded')->group(function (): void {
        Route::livewire('settings/budget', 'pages::settings.budget')->name('budget.edit');
        Route::livewire('settings/notifications', 'pages::settings.notifications')->name('notifications.edit');
    });
});

Route::get('.well-known/passkey-endpoints', fn () => response()->json([
    'enroll' => route('security.edit'),
    'manage' => route('security.edit'),
]))->name('well-known.passkeys');

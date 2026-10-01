<?php

use App\Http\Controllers\NoSpendFromNotificationController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('kezdes', 'pages::onboarding')->name('onboarding');

    Route::middleware('onboarded')->group(function (): void {
        Route::livewire('ma', 'pages::today')->name('dashboard');
        Route::livewire('rogzites', 'pages::entry')->name('entry');
        Route::livewire('terv', 'pages::plan')->name('plan');
        Route::livewire('honap', 'pages::month')->name('month');
        Route::livewire('honap/zaras/{period}', 'pages::close')->name('close');
        Route::livewire('perselyek', 'pages::pockets')->name('pockets');
    });
});

require __DIR__.'/settings.php';

Route::middleware('auth')->group(function (): void {
    Route::post('push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
});

Route::post('push/no-spend/{user}/{date}', NoSpendFromNotificationController::class)
    ->middleware(['signed', 'throttle:10,1'])
    ->where('date', '\d{4}-\d{2}-\d{2}')
    ->name('push.no-spend');

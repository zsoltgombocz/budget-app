<?php

use App\Http\Controllers\DevGateController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\NoSpendFromNotificationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

// Guests see the landing page with the 2.0 waitlist; signed-in users go straight to the app.
Route::get('/', fn () => auth()->check() ? to_route('dashboard') : view('landing'))->name('home');
Route::post('varolista', [WaitlistController::class, 'store'])->middleware('throttle:6,1')->name('waitlist.store');
Route::get('varolista/megerosites/{token}', [WaitlistController::class, 'confirm'])->name('waitlist.confirm');
Route::get('varolista/leiratkozas/{token}', [WaitlistController::class, 'unsubscribe'])->name('waitlist.unsubscribe');

// Shared password page of the dev stack (see the DevGate middleware).
Route::get('dev', [DevGateController::class, 'show'])->name('dev-gate');
Route::post('dev', [DevGateController::class, 'check'])->middleware('throttle:20,1')->name('dev-gate.check');

Route::middleware('guest')->group(function (): void {
    Route::livewire('regisztracio', 'pages::auth.register')->name('register');
    Route::get('belepes/{token}', [MagicLinkController::class, 'show'])->name('magic-link.show');
    Route::post('belepes/{token}', [MagicLinkController::class, 'login'])->middleware('throttle:10,1')->name('magic-link.login');
});

// Public legal pages: ÁSZF, privacy notice, imprint, cookies.
Route::get('{page}', LegalController::class)->whereIn('page', array_keys(LegalController::PAGES))->name('legal');

// Public install guide: shows the steps for the visitor's phone and browser.
Route::view('telepites', 'install')->name('install');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('kezdes', 'pages::onboarding')->name('onboarding');

    Route::middleware('onboarded')->group(function (): void {
        Route::livewire('ma', 'pages::today')->name('dashboard');
        // Opens the quick entry sheet over the dashboard (push notification and shortcut target).
        Route::redirect('rogzites', '/ma?rogzites=1')->name('entry');
        Route::livewire('ertesitesek', 'pages::notifications')->name('notifications.onboarding');
        Route::livewire('terv', 'pages::plan')->name('plan');
        Route::livewire('honap', 'pages::month')->name('month');
        Route::livewire('honap/zaras/{period}', 'pages::close')->name('close');
        Route::livewire('perselyek', 'pages::pockets')->name('pockets');
        Route::livewire('perselyek/hitel/{loan}', 'pages::loan')->name('loan');
        Route::livewire('ujdonsagok', 'pages::changelog')->name('changelog');
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

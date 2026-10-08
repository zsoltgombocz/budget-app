<?php

use App\Http\Controllers\CaptureController;
use App\Http\Middleware\AuthenticateCaptureToken;
use Illuminate\Support\Facades\Route;

// Automatic capture: card payments sent in by the phone (iOS Shortcut, Android MacroDroid).
Route::post('v1/captures', CaptureController::class)
    ->middleware(['throttle:capture', AuthenticateCaptureToken::class])
    ->name('api.captures.store');

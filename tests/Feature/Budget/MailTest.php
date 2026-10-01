<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

it('sends the verification email in Hungarian with the app design', function (): void {
    $user = User::factory()->unverified()->create();
    $user->settings()->update(['locale' => 'hu']);

    app()->setLocale('hu');
    $mail = (new VerifyEmail)->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Erősítsd meg az e-mail-címed')
        ->and($html)->toContain('E-mail-cím megerősítése')
        ->and($html)->toContain('Szia!')
        ->and($html)->toContain('#4BD88A')
        ->and($html)->toContain('/icons/icon-192.png');
});

it('sends notifications in the user\'s language', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable, $locale): bool => $locale === 'hu');
});

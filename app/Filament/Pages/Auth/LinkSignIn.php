<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Admin\AdminSignIn;
use App\Support\SecurityEvents;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Opened from the button in the admin sign-in email. Only shows a button: the token is
 * used up by the button's request, so mail scanners that prefetch links cannot spend it.
 */
class LinkSignIn extends SimplePage
{
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function signIn(): void
    {
        $admin = resolve(AdminSignIn::class)->consumeLink($this->token);

        if ($admin === null) {
            SecurityEvents::record('invalid_admin_link');
            Notification::make()->title(__('This sign-in link has expired or was already used. Request a new one.'))->danger()->send();
            $this->redirect(Filament::getLoginUrl() ?? '/');

            return;
        }

        Filament::auth()->login($admin, remember: true);
        $admin->forceFill(['last_login_at' => now()])->save();
        session()->regenerate();

        $this->redirect(Filament::getUrl());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Actions::make([
                Action::make('signIn')->label(__('Sign in to the admin'))->action('signIn'),
            ])->fullWidth(),
        ]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Admin sign-in');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Tap the button to sign in.');
    }
}

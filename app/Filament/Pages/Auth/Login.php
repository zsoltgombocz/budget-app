<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Admin\AdminSignIn;
use App\Support\SecurityEvents;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Passwordless admin login: the email first, then the 6-digit code from the email
 * (the email also has a sign-in button, see LinkSignIn).
 */
class Login extends BaseLogin
{
    #[Locked]
    public ?string $sentTo = null;

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        if ($this->sentTo === null) {
            $email = is_string($data['email'] ?? null) ? $data['email'] : '';
            resolve(AdminSignIn::class)->send($email);
            $this->sentTo = mb_strtolower(trim($email));
            $this->form->fill();

            return null;
        }

        $code = is_string($data['code'] ?? null) ? $data['code'] : '';
        $admin = resolve(AdminSignIn::class)->consumeCode($this->sentTo, $code);

        if ($admin === null) {
            SecurityEvents::record('wrong_admin_code');

            throw ValidationException::withMessages(['data.code' => __('The code is wrong or has expired.')]);
        }

        Filament::auth()->login($admin, remember: true);
        $admin->forceFill(['last_login_at' => now()])->save();
        session()->regenerate();

        return resolve(LoginResponse::class);
    }

    public function startOver(): void
    {
        $this->sentTo = null;
        $this->form->fill();
    }

    /**
     * Both steps live in one schema; Filament keeps the schema between requests, so the
     * fields switch with visible() instead of rebuilding it.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent()->visible(fn (): bool => $this->sentTo === null),
            $this->getCodeFormComponent()->visible(fn (): bool => $this->sentTo !== null),
        ]);
    }

    protected function getCodeFormComponent(): Component
    {
        return TextInput::make('code')
            ->label(__('Code from the email'))
            ->required()
            ->inputMode('numeric')
            ->autocomplete('one-time-code')
            ->maxLength(6)
            ->autofocus();
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label(fn (): string => $this->sentTo === null ? __('Send me a code') : __('Sign in'))
            ->submit('authenticate');
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction(),
            Action::make('startOver')
                ->label(__('Use another email'))
                ->link()
                ->visible(fn (): bool => $this->sentTo !== null)
                ->action('startOver'),
        ];
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Admin sign-in');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->sentTo === null
            ? __('No password: we email you a code and a sign-in button.')
            : __('If :email is an admin address, the code is on its way. It works for :minutes minutes.', ['email' => $this->sentTo, 'minutes' => AdminSignIn::MINUTES]);
    }
}

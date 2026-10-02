<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Admin\InviteUser;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('Invite'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->modalDescription(__('They get an email with a sign-in button. The app is invite only, so this is how people get in.'))
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
                    TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)->unique('users', 'email'),
                ])
                ->action(function (array $data, InviteUser $inviteUser): void {
                    // The form has validated both fields already.
                    if (! is_string($data['name'] ?? null) || ! is_string($data['email'] ?? null)) {
                        return;
                    }

                    $user = $inviteUser->handle($data['name'], $data['email']);
                    Notification::make()->title(__('Invite sent to :email.', ['email' => $user->email]))->success()->send();
                }),
        ];
    }
}

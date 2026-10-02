<?php

namespace App\Filament\Resources\Users;

use App\Actions\Admin\InviteUser;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users');
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // "Onboarded": the user finished the setup wizard (budget_settings.onboarded_at).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withExists(['budgetSetting as onboarded' => fn (Builder $settings): Builder => $settings->whereNotNull('onboarded_at')]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()
                    ->description(fn (User $record): string => $record->email),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (User $record): string => self::status($record))
                    ->color(fn (string $state): string => match ($state) {
                        __('Disabled') => 'danger',
                        __('Invited') => 'warning',
                        default => 'success',
                    }),
                IconColumn::make('onboarded')->label(__('Setup done'))->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Finished the setup wizard.') : __('Has not finished the setup wizard yet.')),
                TextColumn::make('last_seen_at')->label(__('Last seen'))->since()->sortable()->placeholder('–'),
                TextColumn::make('created_at')->label(__('Joined'))->date('Y. m. d.')->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('disabled_at')->label(__('Disabled'))->nullable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('resendInvite')
                        ->label(__('Send invite again'))
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->visible(fn (User $record): bool => ! $record->isDisabled())
                        ->requiresConfirmation()
                        ->action(function (User $record, InviteUser $inviteUser): void {
                            $inviteUser->handle($record->name, $record->email);
                            Notification::make()->title(__('Invite sent to :email.', ['email' => $record->email]))->success()->send();
                        }),
                    Action::make('disable')
                        ->label(__('Disable'))
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (User $record): bool => ! $record->isDisabled())
                        ->requiresConfirmation()
                        ->modalDescription(__('They are signed out and cannot sign in until you enable them again. Their data stays.'))
                        ->action(fn (User $record) => $record->forceFill(['disabled_at' => now()])->save()),
                    Action::make('enable')
                        ->label(__('Enable'))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->visible(fn (User $record): bool => $record->isDisabled())
                        ->action(fn (User $record) => $record->forceFill(['disabled_at' => null])->save()),
                    DeleteAction::make()
                        ->modalDescription(__('Deletes the account and all of its data. This cannot be undone.')),
                ]),
            ]);
    }

    public static function status(User $user): string
    {
        return match (true) {
            $user->isDisabled() => __('Disabled'),
            $user->invited_at !== null && $user->email_verified_at === null => __('Invited'),
            default => __('Active'),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}

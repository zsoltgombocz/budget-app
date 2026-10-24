<?php

namespace App\Filament\Resources\Users;

use App\Actions\Admin\InviteUser;
use App\Enums\Plan;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Admin;
use App\Models\User;
use App\Services\Plans;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
            // BelongsToUser scopes budget settings to the signed-in user; the admin looks at everyone.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withExists(['budgetSetting as onboarded' => fn (Builder $settings): Builder => $settings->withoutGlobalScopes()->whereNotNull('onboarded_at')]))
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
                TextColumn::make('plan')->label(__('Package'))->badge()
                    ->state(fn (User $record): string => $record->currentPlan()->label())
                    ->description(fn (User $record): ?string => self::planDetail($record))
                    ->color(fn (User $record): string => $record->hasPlusz() ? 'success' : 'gray'),
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
                    Action::make('plan')
                        ->label(__('Set the plan'))
                        ->icon(Heroicon::OutlinedSparkles)
                        ->modalDescription(__('Automatic: the 30-day trial, then Alap. A plan set here wins until its end (empty: no end). Every change is logged.'))
                        ->fillForm(fn (User $record): array => ['plan' => $record->plan->value ?? 'auto', 'plan_until' => $record->plan_until?->toDateString()])
                        ->schema([
                            Select::make('plan')->label(__('Package'))->required()->native(false)
                                ->options(['auto' => __('Automatic'), Plan::Basic->value => Plan::Basic->label(), Plan::Plus->value => Plan::Plus->label()]),
                            DatePicker::make('plan_until')->label(__('Until'))->native(false)->displayFormat('Y. m. d.')->minDate(now()),
                            TextInput::make('note')->label(__('Note'))->maxLength(255),
                        ])
                        ->action(function (User $record, array $data, Plans $plans): void {
                            $admin = Filament::auth()->user();
                            $plans->set(
                                $record,
                                Plan::tryFrom(is_string($data['plan'] ?? null) ? $data['plan'] : ''),
                                is_string($data['plan_until'] ?? null) && $data['plan_until'] !== '' ? CarbonImmutable::parse($data['plan_until']) : null,
                                $admin instanceof Admin ? $admin : null,
                                is_string($data['note'] ?? null) && $data['note'] !== '' ? $data['note'] : null,
                            );
                            Notification::make()->title(__('Plan of :name: :plan', ['name' => $record->name, 'plan' => $record->currentPlan()->label()]))->success()->send();
                        }),
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

    /**
     * Under the plan badge: until when a set plan or the offer runs, or when the trial ends.
     */
    public static function planDetail(User $user): ?string
    {
        $until = match ($user->currentPlan()) {
            Plan::Trial => resolve(Plans::class)->trialEndsAt($user),
            Plan::PlusTrial => $user->plus_offer_until,
            default => $user->plan !== null ? $user->plan_until : null,
        };

        if ($until !== null) {
            return __('until :date', ['date' => $until->format('Y. m. d')]);
        }

        if ($user->plan === null) {
            return null;
        }

        return __('Set by an admin');
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

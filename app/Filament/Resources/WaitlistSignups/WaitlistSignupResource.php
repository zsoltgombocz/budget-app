<?php

namespace App\Filament\Resources\WaitlistSignups;

use App\Filament\Resources\WaitlistSignups\Pages\ManageWaitlistSignups;
use App\Models\WaitlistSignup;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The 2.0 waitlist from the landing page: who asked to hear about the launch, and who confirmed.
 */
class WaitlistSignupResource extends Resource
{
    protected static ?string $model = WaitlistSignup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('waitlist signup');
    }

    public static function getPluralModelLabel(): string
    {
        return __('waitlist');
    }

    public static function getNavigationLabel(): string
    {
        return __('Waitlist');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) WaitlistSignup::query()->whereNotNull('confirmed_at')->count();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label(__('Email'))->searchable()->copyable(),
                IconColumn::make('confirmed_at')->label(__('Confirmed'))->boolean()->getStateUsing(fn (WaitlistSignup $record): bool => $record->isConfirmed()),
                TextColumn::make('created_at')->label(__('Signed up'))->dateTime('Y. m. d. H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('confirmed_at')->label(__('Confirmed'))->nullable(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWaitlistSignups::route('/'),
        ];
    }
}

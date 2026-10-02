<?php

namespace App\Filament\Resources\Admins;

use App\Filament\Resources\Admins\Pages\ManageAdmins;
use App\Models\Admin;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Admin accounts. They sign in with an emailed code, so adding one only needs a name and email.
 */
class AdminResource extends Resource
{
    protected static ?string $model = Admin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('admin');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admins');
    }

    public static function getNavigationLabel(): string
    {
        return __('Admins');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)->unique(ignoreRecord: true)
                    ->helperText(__('They sign in on this page with a code sent to this address.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()
                    ->description(fn (Admin $record): string => $record->email),
                TextColumn::make('last_login_at')->label(__('Last sign-in'))->since()->placeholder('–'),
                TextColumn::make('created_at')->label(__('Added'))->date('Y. m. d.'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Admin $record): bool => ! $record->is(Filament::auth()->user())),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withRandomPassword(array $data): array
    {
        return [...$data, 'password' => Str::random(64)];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdmins::route('/'),
        ];
    }
}

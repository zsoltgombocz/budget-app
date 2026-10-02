<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline numbers on the admin dashboard. Transactions are counted across all users,
 * so the per-user scope is skipped on purpose.
 */
class UsageStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $users = User::query();

        return [
            Stat::make(__('Users'), (string) $users->count())
                ->description(__(':count invited, not signed in yet', ['count' => User::query()->whereNotNull('invited_at')->whereNull('email_verified_at')->count()])),
            Stat::make(__('Active in the last 7 days'), (string) User::query()->where('last_seen_at', '>=', now()->subDays(7))->count())
                ->description(__(':count today', ['count' => User::query()->where('last_seen_at', '>=', today())->count()])),
            Stat::make(__('Spendings recorded, last 24 hours'), (string) Transaction::query()->withoutGlobalScopes()->where('created_at', '>=', now()->subDay())->count()),
        ];
    }
}

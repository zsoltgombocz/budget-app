<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\UsageStats;
use App\Http\Middleware\TrackUserActivity;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Admin panel on its own host (budget.admin_domain). It has no login page of its own:
 * guests are sent to the app's passwordless sign-in on the same host, and only admins
 * get in (User::canAccessPanel).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $domain = config('budget.admin_domain');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(is_string($domain) && $domain !== '' ? $domain : null)
            ->brandName('Budget admin')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->darkMode(true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                UsageStats::class,
            ])
            ->navigationItems([
                NavigationItem::make('monitoring')
                    ->label(fn (): string => __('Monitoring'))
                    ->icon(Heroicon::OutlinedChartBar)
                    ->url(fn (): string => url(config()->string('pulse.path', 'pulse')))
                    ->sort(2),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Keeps last_seen_at fresh; disabled admins are already refused by canAccessPanel.
                TrackUserActivity::class,
            ]);
    }
}

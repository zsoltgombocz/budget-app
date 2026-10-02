<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\LinkSignIn;
use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\UsageStats;
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
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Admin panel on its own host (budget.admin_domain), with its own accounts (App\Models\Admin,
 * "admin" guard) and a passwordless login: an emailed code or sign-in link.
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
            ->authGuard('admin')
            ->login(Login::class)
            ->routes(fn () => Route::get('login/link/{token}', LinkSignIn::class)->name('auth.link'))
            ->brandName('Budget admin')
            // Production is green like the app; the dev stack is blue like its app.
            ->colors([
                'primary' => app()->environment('staging') ? Color::Blue : Color::hex('#1FB866'),
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
                NavigationItem::make('nightwatch')
                    ->label('Nightwatch')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => config()->string('budget.nightwatch_url', ''), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => config()->string('budget.nightwatch_url', '') !== '')
                    ->sort(3),
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
            ]);
    }
}

<?php

namespace App\Providers;

use App\Livewire\Pulse\SecurityEvents;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Compile the design's presentational components with Blaze.
        Blaze::optimize()->in(resource_path('views/components/ui'));

        // Monitoring (Pulse) is for active admins, like the admin panel.
        Gate::define('viewPulse', fn (User $user): bool => $user->is_admin && ! $user->isDisabled());
        Livewire::component('pulse.security-events', SecurityEvents::class);

        // The dev stack sends blue emails, like its app theme.
        if ($this->app->environment('staging')) {
            config(['mail.markdown.theme' => 'dev']);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Model::shouldBeStrict(! app()->isProduction());

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

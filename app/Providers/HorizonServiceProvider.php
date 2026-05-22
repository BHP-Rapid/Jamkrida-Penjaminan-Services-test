<?php

namespace App\Providers;

use App\Http\Controllers\HorizonAuthController;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->registerAuthRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * Authentication is handled by HorizonSessionAuthMiddleware. The gate
     * always returns true so the middleware is the single source of access
     * control.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return true;
        });
    }

    private function registerAuthRoutes(): void
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');

        Route::middleware('web')
            ->prefix($horizonPath)
            ->name('horizon.')
            ->group(function () {
                Route::get('/login', [HorizonAuthController::class, 'showLogin'])->name('login');
                Route::post('/login', [HorizonAuthController::class, 'login'])->name('login.store');
                Route::post('/logout', [HorizonAuthController::class, 'logout'])->name('logout');
            });
    }
}

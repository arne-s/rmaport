<?php

namespace App\Providers;

use Detection\MobileDetect;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class MobileDetectServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('MobileDetect', function ($app) {
            return new MobileDetect();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        View::share('browser', app('MobileDetect'));
    }
}

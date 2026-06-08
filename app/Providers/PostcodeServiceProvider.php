<?php

namespace App\Providers;

use App\Services\PostcodeService;
use Illuminate\Support\ServiceProvider;

class PostcodeServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('postcode', function ($app) {
            return new PostcodeService();
        });
    }
}

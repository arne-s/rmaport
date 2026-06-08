<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Mollie\Api\MollieApiClient;

class MollieServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('mollie', function () {
            $api = new MollieApiClient();
            $api->setApiKey(config('services.mollie.key'));

            return $api;
        });
    }
}

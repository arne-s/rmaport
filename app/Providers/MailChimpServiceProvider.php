<?php

namespace App\Providers;

use App\Services\MailChimpService;
use Illuminate\Support\ServiceProvider;

class MailChimpServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('mailchimp', function ($app) {
            return new MailChimpService();
        });
    }
}

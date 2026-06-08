<?php

namespace App\Providers;

use App\Services\Exact\Accounts\ExactAccounts;
use App\Services\Exact\Customers\ExactCustomerImportService;
use App\Services\Exact\Documents\ExactDocumentImportService;
use App\Services\Exact\Documents\ExactDocuments;
use App\Services\Exact\Products\ExactProductImportService;
use App\Services\Exact\Products\ExactProducts;
use App\Services\ExactOnlineService;
use Illuminate\Support\ServiceProvider;

class ExactOnlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExactOnlineService::class, function () {
            return new ExactOnlineService();
        });

        $this->app->alias(ExactOnlineService::class, 'exact');

        $this->app->singleton(ExactProducts::class, function ($app) {
            return new ExactProducts($app->make(ExactOnlineService::class));
        });

        $this->app->alias(ExactProducts::class, 'exact.products');

        $this->app->singleton(ExactProductImportService::class, function ($app) {
            return new ExactProductImportService(
                $app->make(ExactProducts::class),
                $app->make(ExactOnlineService::class),
            );
        });

        $this->app->singleton(ExactAccounts::class, function ($app) {
            return new ExactAccounts($app->make(ExactOnlineService::class));
        });

        $this->app->singleton(ExactCustomerImportService::class, function ($app) {
            return new ExactCustomerImportService(
                $app->make(ExactAccounts::class),
                $app->make(ExactOnlineService::class),
            );
        });

        $this->app->singleton(ExactDocuments::class, function ($app) {
            return new ExactDocuments(
                $app->make(ExactOnlineService::class),
                $app->make(ExactAccounts::class),
            );
        });

        $this->app->singleton(ExactDocumentImportService::class, function ($app) {
            return new ExactDocumentImportService(
                $app->make(ExactDocuments::class),
                $app->make(ExactOnlineService::class),
            );
        });
    }
}

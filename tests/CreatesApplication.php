<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * When configuration is cached (bootstrap/cache/config.php), Laravel loads that file during tests and
     * skips loading .env, so RefreshDatabase runs migrate:fresh against the same connection as production cache
     * (typically the developer MySQL DB). Point APP_CONFIG_CACHE at a non-existent path so config + env apply.
     *
     * The actual test DB name/driver should be set in phpunit.xml (see DB_CONNECTION / DB_DATABASE there).
     */
    public function createApplication(): Application
    {
        $basePath = dirname(__DIR__);

        if ($this->isTestingEnvironment()) {
            $skipConfigPath = $basePath.'/bootstrap/cache/.phpunit-skip-config-cache';
            $_ENV['APP_CONFIG_CACHE'] = $skipConfigPath;
            putenv('APP_CONFIG_CACHE='.$skipConfigPath);

            // Pest's default phpunit stub does not set DB_*; without this, .env would still target the dev database.
            $_ENV['DB_CONNECTION'] ??= 'mysql';
            $_ENV['DB_DATABASE'] ??= 'rdmobility_testing';
            putenv('DB_CONNECTION='.$_ENV['DB_CONNECTION']);
            putenv('DB_DATABASE='.$_ENV['DB_DATABASE']);
        }

        $app = require $basePath.'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function isTestingEnvironment(): bool
    {
        return ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'testing';
    }
}

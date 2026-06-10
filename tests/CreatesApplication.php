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
     * skips loading .env, which can point tests at the developer MySQL database. Point APP_CONFIG_CACHE
     * at a non-existent path so config + env apply.
     *
     * The test database name/driver must be set in phpunit.xml (DB_CONNECTION / DB_DATABASE).
     * Feature tests use DatabaseTransactions only — they must never run migrate:fresh.
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

        if ($this->isTestingEnvironment()) {
            $this->guardAgainstDevelopmentDatabase($basePath);
        }

        return $app;
    }

    /**
     * Abort if tests are configured to use the same database as .env (dev data).
     */
    private function guardAgainstDevelopmentDatabase(string $basePath): void
    {
        $connection = (string) config('database.default');
        $testDatabase = (string) config("database.connections.{$connection}.database");
        $devDatabase = $this->readEnvDatabaseName($basePath);

        if ($devDatabase === null || $devDatabase === '') {
            return;
        }

        if ($testDatabase === $devDatabase) {
            throw new \RuntimeException(
                "Refusing to run tests against the development database [{$testDatabase}]. "
                .'Configure a separate test database in phpunit.xml (currently rdmobility_testing).'
            );
        }
    }

    private function readEnvDatabaseName(string $basePath): ?string
    {
        $envPath = $basePath.'/.env';

        if (! is_file($envPath)) {
            return null;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_starts_with($line, 'DB_DATABASE=')) {
                continue;
            }

            $value = trim(substr($line, strlen('DB_DATABASE=')));

            return trim($value, " \t\n\r\0\x0B\"'");
        }

        return null;
    }

    private function isTestingEnvironment(): bool
    {
        return ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'testing';
    }
}

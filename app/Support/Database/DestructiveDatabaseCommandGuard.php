<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;

final class DestructiveDatabaseCommandGuard
{
    /**
     * @var list<string>
     */
    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'db:wipe',
    ];

    public const ALLOW_ENV = 'ALLOW_DESTRUCTIVE_DB_COMMANDS';

    public const TEST_DATABASE = 'rdmobility_testing';

    public function __construct(private Application $app) {}

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        if (filter_var(env(self::ALLOW_ENV, false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === '') {
            return;
        }

        if ($this->app->environment('testing') && ! $this->isAllowedTestingDatabase($database)) {
            throw new \RuntimeException(
                "Refusing [{$event->command}]: APP_ENV=testing but DB_DATABASE is [{$database}]. "
                .'Copy .env.testing.example to .env.testing (DB_DATABASE='.self::TEST_DATABASE.') '
                .'or set '.self::ALLOW_ENV.'=true only when you intentionally target this database.'
            );
        }

        if ($this->app->environment('production')) {
            throw new \RuntimeException(
                "Refusing [{$event->command}] in production. Set ".self::ALLOW_ENV.'=true to override.'
            );
        }
    }

    public function isAllowedTestingDatabase(string $database): bool
    {
        if ($database === self::TEST_DATABASE) {
            return true;
        }

        return str_ends_with($database, '_testing');
    }

    /**
     * @return list<string>
     */
    public static function destructiveCommands(): array
    {
        return self::DESTRUCTIVE_COMMANDS;
    }
}

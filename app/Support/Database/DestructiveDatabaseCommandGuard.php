<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\WipeCommand;

final class DestructiveDatabaseCommandGuard
{
    /**
     * @var list<string>
     */
    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    public const ALLOW_ENV = 'ALLOW_DESTRUCTIVE_DB_COMMANDS';

    public const TEST_DATABASE = 'rdmobility_testing';

    public function __construct(private Application $app) {}

    /**
     * Register Laravel's built-in command prohibitions (reliable even when CommandStarting does not fire).
     */
    public function applyCommandProhibitions(): void
    {
        $prohibit = $this->shouldProhibitDestructiveCommands();

        FreshCommand::prohibit($prohibit);
        RefreshCommand::prohibit($prohibit);
        ResetCommand::prohibit($prohibit);
        WipeCommand::prohibit($prohibit);
    }

    public function shouldProhibitDestructiveCommands(): bool
    {
        if (filter_var(env(self::ALLOW_ENV, false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        if ($this->app->environment('production')) {
            return true;
        }

        $database = $this->currentDatabaseName();

        if ($database === '') {
            return false;
        }

        return ! $this->isWipeSafeDatabase($database);
    }

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        if (! $this->shouldProhibitDestructiveCommands()) {
            return;
        }

        throw new \RuntimeException($this->refusalMessage($event->command));
    }

    public function isWipeSafeDatabase(string $database): bool
    {
        if ($database === ':memory:') {
            return true;
        }

        if ($database === self::TEST_DATABASE) {
            return true;
        }

        return str_ends_with($database, '_testing');
    }

    /**
     * @deprecated Use isWipeSafeDatabase() instead.
     */
    public function isAllowedTestingDatabase(string $database): bool
    {
        return $this->isWipeSafeDatabase($database);
    }

    /**
     * @return list<string>
     */
    public static function destructiveCommands(): array
    {
        return self::DESTRUCTIVE_COMMANDS;
    }

    private function currentDatabaseName(): string
    {
        $connection = (string) config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    private function refusalMessage(string $command): string
    {
        $database = $this->currentDatabaseName();

        if ($this->app->environment('production')) {
            return "Refusing [{$command}] in production. Set ".self::ALLOW_ENV.'=true to override.';
        }

        if ($this->app->configurationIsCached()) {
            return "Refusing [{$command}]: configuration is cached and DB_DATABASE is [{$database}]. "
                .'Run `php artisan config:clear` so `--env=testing` can load `.env.testing`, '
                .'or set '.self::ALLOW_ENV.'=true only when you intentionally wipe this database.';
        }

        if ($this->app->environment('testing') && ! $this->isWipeSafeDatabase($database)) {
            return "Refusing [{$command}]: APP_ENV=testing but DB_DATABASE is [{$database}]. "
                .'Ensure `.env.testing` exists (copy from `.env.testing.example`) and run `php artisan config:clear`, '
                .'or set '.self::ALLOW_ENV.'=true only when you intentionally target this database.';
        }

        return "Refusing [{$command}] against [{$database}]. "
            .'Destructive commands are only allowed on dedicated test databases (for example '
            .self::TEST_DATABASE.'). Set '.self::ALLOW_ENV.'=true to override.';
    }
}

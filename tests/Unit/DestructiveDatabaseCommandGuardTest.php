<?php

use App\Support\Database\DestructiveDatabaseCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

uses(TestCase::class);

it('allows the dedicated testing database name', function (): void {
    $guard = new DestructiveDatabaseCommandGuard(app());

    expect($guard->isAllowedTestingDatabase(DestructiveDatabaseCommandGuard::TEST_DATABASE))->toBeTrue()
        ->and($guard->isAllowedTestingDatabase('myapp_testing'))->toBeTrue()
        ->and($guard->isAllowedTestingDatabase('rma-portal'))->toBeFalse();
});

it('lists destructive database commands', function (): void {
    expect(DestructiveDatabaseCommandGuard::destructiveCommands())->toContain('migrate:fresh', 'db:wipe');
});

it('refuses destructive commands when testing env targets a development database', function (): void {
    $this->app->detectEnvironment(fn (): string => 'testing');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'rma-portal']);

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    expect(fn () => $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput)))
        ->toThrow(RuntimeException::class, 'APP_ENV=testing but DB_DATABASE is [rma-portal]');
});

it('allows destructive commands on the testing database when app env is testing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'testing');
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => DestructiveDatabaseCommandGuard::TEST_DATABASE,
    ]);

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput));

    expect(true)->toBeTrue();
});

it('allows destructive commands when explicitly overridden', function (): void {
    $this->app->detectEnvironment(fn (): string => 'testing');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'rma-portal']);

    putenv(DestructiveDatabaseCommandGuard::ALLOW_ENV.'=true');
    $_ENV[DestructiveDatabaseCommandGuard::ALLOW_ENV] = 'true';

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput));

    putenv(DestructiveDatabaseCommandGuard::ALLOW_ENV);
    unset($_ENV[DestructiveDatabaseCommandGuard::ALLOW_ENV]);

    expect(true)->toBeTrue();
});

it('refuses destructive commands in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'production-db']);

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    expect(fn () => $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput)))
        ->toThrow(RuntimeException::class, 'Refusing [migrate:fresh] in production');
});

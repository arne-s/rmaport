<?php

use App\Support\Database\DestructiveDatabaseCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    FreshCommand::prohibit(false);
});

it('allows the dedicated testing database name', function (): void {
    $guard = new DestructiveDatabaseCommandGuard(app());

    expect($guard->isWipeSafeDatabase(DestructiveDatabaseCommandGuard::TEST_DATABASE))->toBeTrue()
        ->and($guard->isWipeSafeDatabase('myapp_testing'))->toBeTrue()
        ->and($guard->isWipeSafeDatabase('rma-portal'))->toBeFalse();
});

it('lists destructive database commands', function (): void {
    expect(DestructiveDatabaseCommandGuard::destructiveCommands())->toContain('migrate:fresh', 'db:wipe', 'migrate:reset');
});

it('refuses destructive commands when testing env targets a development database', function (): void {
    $this->app->detectEnvironment(fn (): string => 'testing');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'rma-portal']);

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    expect($guard->shouldProhibitDestructiveCommands())->toBeTrue()
        ->and(fn () => $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput)))
        ->toThrow(RuntimeException::class, 'APP_ENV=testing but DB_DATABASE is [rma-portal]');
});

it('registers migrate fresh as prohibited on the local development database', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'rma-portal']);

    app(DestructiveDatabaseCommandGuard::class)->applyCommandProhibitions();

    $reflection = new ReflectionClass(FreshCommand::class);
    $property = $reflection->getProperty('prohibitedFromRunning');
    $property->setAccessible(true);

    expect($property->getValue())->toBeTrue();
});

it('allows destructive commands on the testing database when app env is testing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'testing');
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => DestructiveDatabaseCommandGuard::TEST_DATABASE,
    ]);

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    expect($guard->shouldProhibitDestructiveCommands())->toBeFalse();

    $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput()));

    expect(true)->toBeTrue();
});

it('allows destructive commands when explicitly overridden', function (): void {
    $this->app->detectEnvironment(fn (): string => 'testing');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'rma-portal']);

    putenv(DestructiveDatabaseCommandGuard::ALLOW_ENV.'=true');
    $_ENV[DestructiveDatabaseCommandGuard::ALLOW_ENV] = 'true';

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    expect($guard->shouldProhibitDestructiveCommands())->toBeFalse();

    $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput()));

    putenv(DestructiveDatabaseCommandGuard::ALLOW_ENV);
    unset($_ENV[DestructiveDatabaseCommandGuard::ALLOW_ENV]);

    expect(true)->toBeTrue();
});

it('refuses destructive commands in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'production-db']);

    $guard = new DestructiveDatabaseCommandGuard($this->app);

    expect($guard->shouldProhibitDestructiveCommands())->toBeTrue()
        ->and(fn () => $guard->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput)))
        ->toThrow(RuntimeException::class, 'Refusing [migrate:fresh] in production');
});

it('blocks migrate fresh via artisan on the development database', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'rma-portal']);

    app(DestructiveDatabaseCommandGuard::class)->applyCommandProhibitions();

    $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('prohibited');
});

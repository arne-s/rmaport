<?php

use Tests\TestCase;

uses(TestCase::class);

it('uses a dedicated test database separate from env development database', function (): void {
    $devDatabase = null;
    $envPath = dirname(__DIR__, 2).'/.env';

    if (is_file($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'DB_DATABASE=')) {
                $devDatabase = trim(trim(substr($line, strlen('DB_DATABASE='))), " \t\n\r\0\x0B\"'");
                break;
            }
        }
    }

    $testDatabase = config('database.connections.'.config('database.default').'.database');

    expect($testDatabase)->toBe('rdmobility_testing');

    if ($devDatabase !== null && $devDatabase !== '') {
        expect($testDatabase)->not->toBe($devDatabase);
    }
});

<?php

use App\Support\FormatDisplayDate;
use Illuminate\Support\Carbon;

it('formats long date time in dutch style', function (): void {
    Carbon::setLocale('nl');

    $formatted = FormatDisplayDate::longDateTime(Carbon::parse('2026-06-10 13:22:00'));

    expect($formatted)->toBe('10 jun. 2026 13:22');
});

it('formats long date without time', function (): void {
    Carbon::setLocale('nl');

    $formatted = FormatDisplayDate::longDate(Carbon::parse('2026-06-10'));

    expect($formatted)->toBe('10 jun. 2026');
});

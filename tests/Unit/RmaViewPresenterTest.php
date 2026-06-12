<?php

use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource\Support\RmaViewPresenter;
use App\Models\Rma;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('orders combined general fields without rma number and status', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'product_name' => 'Test Scootmobiel',
        'reference' => 'REF-123',
        'quantity' => 1,
    ]);

    $labels = array_column(RmaViewPresenter::combinedGeneralFields($rma), 'label');

    expect($labels)->toBe([
        'Klant',
        'Invoerdatum en tijd',
        'Aankoopdatum',
        'Betalingsmethode',
        'Artikelnaam',
        'Aantal',
        'Artikelnummer',
        'Merk',
        'EAN',
        'Artikelgroep',
        'Serienummer',
        'IMEI',
        'Accessoires',
        'Taal',
        'Referentie',
        'Ordernummer',
        'Defect ID',
        'Global ID',
        'Barcode',
        'Pakbon',
    ])->and($labels)->not->toContain('RMA Nummer', 'Status');
});

it('formats invoerdatum en tijd from created_at', function (): void {
    Carbon::setTestNow('2026-06-11 12:34:00');

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-002',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $headerFields = RmaViewPresenter::combinedGeneralHeaderFields($rma);

    expect($headerFields[1])->toBe([
        'label' => 'Invoerdatum en tijd',
        'value' => '11/06/26 - 12:34',
    ]);

    Carbon::setTestNow();
});

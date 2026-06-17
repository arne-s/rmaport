<?php

use App\Enums\CustomerStatus;
use App\Enums\RmaStatus;
use App\Filament\Resources\RmaResource;
use App\Mail\ExportRmaMail;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportRow;
use App\Models\Rma;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('exposes dot notation template vars for import batch export mail', function (): void {
    $customer = Customer::make([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt BV',
        'email' => 'klant@example.com',
    ]);

    $row = ImportRow::make([
        'reference' => 'ROW-REF',
    ]);
    $row->setRelation('customer', $customer);

    $batch = ImportBatch::make([
        'file_name' => 'import.xlsx',
        'uid' => 'IMP-1001',
        'reference' => 'REF-1001',
        'shipment_reference' => 'SHIP-1001',
        'track_trace_nr' => 'TT-1001',
        'import_date' => Carbon::parse('2026-06-10'),
        'shipment_date' => Carbon::parse('2026-06-12'),
    ]);
    $batch->setRelation('importRows', collect([$row]));

    $export = ImportExport::make([
        'uid' => 'EXP-1001',
        'file_name' => 'export.xlsx',
        'file_disk' => 'local',
    ]);

    $mail = new ExportRmaMail($batch, $export, 'to@example.com');
    $vars = $mail->getTemplateVars();

    expect($vars)->toHaveKeys([
        'customer.name',
        'customer.email',
        'import.uid',
        'import.reference',
        'import.shipment_reference',
        'import.file_name',
        'import.import_date',
        'import.shipment_date',
        'import.track_trace_nr',
    ])
        ->and($vars['customer.name'])->toBe('MediaMarkt BV')
        ->and($vars['customer.email'])->toBe('klant@example.com')
        ->and($vars['import.uid'])->toBe('IMP-1001')
        ->and($vars['import.reference'])->toBe('REF-1001')
        ->and($vars['import.shipment_reference'])->toBe('SHIP-1001')
        ->and($vars['import.file_name'])->toBe('import.xlsx')
        ->and($vars['import.track_trace_nr'])->toBe('TT-1001')
        ->and($vars['import.import_date'])->not->toBe('')
        ->and($vars['import.shipment_date'])->not->toBe('');
});

it('interpolates dot notation placeholders in subject and body', function (): void {
    $customer = Customer::make([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt BV',
        'email' => 'klant@example.com',
    ]);

    $row = ImportRow::make();
    $row->setRelation('customer', $customer);

    $batch = ImportBatch::make([
        'reference' => 'REF-INTERPOLATE',
    ]);
    $batch->setRelation('importRows', collect([$row]));

    $export = ImportExport::make([
        'uid' => 'EXP-2001',
        'file_name' => 'export.xlsx',
        'file_disk' => 'local',
    ]);

    $mail = new ExportRmaMail($batch, $export, 'to@example.com');

    expect($mail->interpolatePlaceholders('RMA voor #[import.reference]'))
        ->toBe('RMA voor #REF-INTERPOLATE')
        ->and($mail->interpolatePlaceholders('Beste [customer.name], ref [import.reference]'))
        ->toBe('Beste MediaMarkt BV, ref REF-INTERPOLATE');
});

it('defines export rma mail class for email template registration', function (): void {
    expect(class_exists(ExportRmaMail::class))->toBeTrue();

    $source = file_get_contents(app_path('Mail/ExportRmaMail.php'));

    expect($source)
        ->toContain('HasTemplate')
        ->toContain("'customer.name'")
        ->toContain("'import.reference'")
        ->toContain('interpolatePlaceholders');
});

it('includes rma column as first linked column in send export table', function (): void {
    $row = ImportRow::make([
        'reference' => 'REF-RMA-LINK',
        'ean_nr' => '0846885011362',
    ]);

    $rma = Rma::make([
        'uid' => 'RMA-LINK-001',
        'status' => RmaStatus::Open,
    ]);
    $rma->id = 42;

    $row->setRelation('rma', $rma);

    $html = view('filament.resources.import-tasks.partials.send-export-rmas-table', [
        'rows' => collect([$row]),
    ])->render();

    expect($html)
        ->toContain('>RMA</th>')
        ->toContain('RMA-LINK-001')
        ->toContain('import-row-rma-link')
        ->toContain('target="_blank"')
        ->toContain(RmaResource::getUrl('view', ['record' => $rma]));
});

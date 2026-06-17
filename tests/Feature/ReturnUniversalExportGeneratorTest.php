<?php

use App\Enums\CustomerStatus;
use App\Enums\RmaStatus;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Rma;
use App\Models\Source;
use App\Models\User;
use App\Services\Export\CreateImportBatchExportAction;
use App\Services\Import\ParseImportFileAction;
use App\Services\Import\ProcessImportBatchAction;
use App\Support\FormatDisplayDate;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Database\Seeders\RmaImportTestProductsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    $this->seed(ImportTemplateSeeder::class);
    $this->seed(RmaImportTestProductsSeeder::class);
});

function readExportCell(string $path, string $cell): string
{
    $sheet = IOFactory::load($path)->getActiveSheet();

    return trim((string) $sheet->getCell($cell)->getCalculatedValue());
}

it('generates return universal export from template with header and row placeholders', function (): void {
    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $parseResult = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    $customer = Customer::query()->findOrFail($parseResult->detectedCustomerId);
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $uploadedFile = new UploadedFile(
        $fixture,
        'media-markt-export.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $result = app(ProcessImportBatchAction::class)(
        parseResult: $parseResult,
        batchData: [
            'customer_id' => $customer->id,
            'track_trace_nr' => 'TT123',
            'reference' => 'REF-001',
            'import_date' => '2026-06-01',
            'shipment_date' => '2026-06-01',
        ],
        file: $uploadedFile,
        user: $user,
    );

    /** @var ImportBatch $batch */
    $batch = $result['batch'];

    $export = app(CreateImportBatchExportAction::class)(
        $batch->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma', 'importRows.customer']),
        $user,
    );

    expect($export)->not->toBeNull();

    $exportPath = storage_path("app/exports/{$batch->id}/{$export->uid}.xlsx");
    expect($exportPath)->toBeReadableFile();

    expect(readExportCell($exportPath, 'B2'))->toBe($customer->getName())
        ->and(readExportCell($exportPath, 'B3'))->toBe('REF-001')
        ->and(readExportCell($exportPath, 'B4'))->toBe(FormatDisplayDate::longDate(Carbon::parse('2026-06-01')));

    $rmaUids = ImportRow::query()
        ->where('import_id', $batch->id)
        ->with('rma')
        ->orderBy('id')
        ->get()
        ->map(fn (ImportRow $row): string => (string) $row->rma?->uid)
        ->filter()
        ->values()
        ->all();

    expect($rmaUids)->not->toBeEmpty();

    foreach ($rmaUids as $index => $uid) {
        $rowNumber = 14 + $index;
        expect(readExportCell($exportPath, "E{$rowNumber}"))->toBe($uid);
    }
});

it('includes row comments in the return universal export', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
        'debtor_number' => 'MM-001',
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $source = Source::query()->create([
        'name' => 'MediaMarkt',
        'import_template_id' => $template->id,
        'customer_id' => $customer->id,
    ]);

    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Comments',
    ]);

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 2,
        'successful_rows' => 2,
        'import_template_id' => $template->id,
        'reference' => 'REF-COMMENTS',
        'import_date' => '2026-06-10',
    ]);

    $firstRow = ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => 'REF-ROW-1',
        'ean_nr' => '8715465017075',
        'product_name' => 'Product A',
        'return_reason' => 'Defect',
    ]);

    $secondRow = ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => 'REF-ROW-2',
        'ean_nr' => '0846885011362',
        'product_name' => 'Product B',
        'return_reason' => 'Beschadigd',
    ]);

    Rma::query()->create([
        'import_row_id' => $firstRow->id,
        'customer_id' => $customer->id,
        'uid' => 'RMA-001',
        'status' => RmaStatus::Open,
    ]);

    Rma::query()->create([
        'import_row_id' => $secondRow->id,
        'customer_id' => $customer->id,
        'uid' => 'RMA-002',
        'status' => RmaStatus::Open,
    ]);

    /** @var ImportExport $export */
    $export = app(CreateImportBatchExportAction::class)(
        $batch->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma', 'importRows.customer']),
        $user,
        [
            $firstRow->id => 'Defect aan linker wiel',
            $secondRow->id => 'Doos beschadigd',
        ],
    );

    $exportPath = storage_path("app/exports/{$batch->id}/{$export->uid}.xlsx");

    expect(readExportCell($exportPath, 'A14'))->toBe('REF-ROW-1')
        ->and(readExportCell($exportPath, 'C14'))->toBe('Product A')
        ->and(readExportCell($exportPath, 'D14'))->toBe('Defect')
        ->and(readExportCell($exportPath, 'E14'))->toBe('RMA-001')
        ->and(readExportCell($exportPath, 'F14'))->toBe('Defect aan linker wiel')
        ->and(readExportCell($exportPath, 'A15'))->toBe('REF-ROW-2')
        ->and(readExportCell($exportPath, 'C15'))->toBe('Product B')
        ->and(readExportCell($exportPath, 'D15'))->toBe('Beschadigd')
        ->and(readExportCell($exportPath, 'E15'))->toBe('RMA-002')
        ->and(readExportCell($exportPath, 'F15'))->toBe('Doos beschadigd');
});

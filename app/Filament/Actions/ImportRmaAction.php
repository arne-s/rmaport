<?php

namespace App\Filament\Actions;

use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Models\Customer;
use App\Models\ImportTemplate;
use App\Services\Import\ImportRowValidationResult;
use App\Services\Import\ImportRowValidator;
use App\Services\Import\ParseImportFileAction;
use App\Services\Import\ProcessImportBatchAction;
use App\Support\Import\ImportParseResult;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

class ImportRmaAction extends Action
{
    private const SESSION_KEY = 'rma_staging_import';

    public static function getDefaultName(): ?string
    {
        return 'import_rma';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Import');
        $this->icon(Heroicon::OutlinedArrowUpTray);
        $this->modalHeading('RMA\'s importeren');
        $this->modalSubmitActionLabel('Importeren');
        $this->modalWidth('3xl');
        $this->color('gray');
        $this->successRedirectUrl(fn (): string => ImportTaskResource::getUrl('index'));

        $this->beforeFormFilled(function (): void {
            if (ImportTemplate::query()->exists()) {
                return;
            }

            Notification::make()
                ->title('Importtemplates ontbreken')
                ->body('Voer eerst `php artisan db:seed --class=ImportTemplateSeeder` uit.')
                ->danger()
                ->send();

            throw new Halt;
        });

        $confirmFieldsVisible = fn (Get $get): bool => filled($get('row_count'));

        $this->schema([
            FileUpload::make('file')
                ->label('Bestand')
                ->acceptedFileTypes([
                    'text/csv',
                    'text/plain',
                    'application/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->rule(static function (): \Closure {
                    return static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        try {
                            $file = self::resolveUploadedFile($value);
                        } catch (InvalidArgumentException) {
                            $fail('Upload een geldig Excel- of CSV-bestand.');

                            return;
                        }

                        if (! in_array(strtolower($file->getClientOriginalExtension() ?? ''), ['csv', 'txt', 'xlsx'], true)) {
                            $fail('Het bestand moet een .csv, .txt of .xlsx bestand zijn.');
                        }
                    };
                })
                ->storeFiles(false)
                ->visibility('private')
                ->required()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set, ParseImportFileAction $parseImportFile): void {
                    self::clearParsedImportState($set);

                    if (blank($state)) {
                        return;
                    }

                    try {
                        $file = self::resolveUploadedFile($state);
                        $extension = strtolower($file->getClientOriginalExtension() ?? '');

                        $parseResult = $parseImportFile(
                            $file->getRealPath(),
                            $extension,
                            null,
                        );

                        session()->put(self::SESSION_KEY, [
                            'template_id' => $parseResult->template->id,
                            'metadata' => $parseResult->metadata,
                            'rows' => $parseResult->rows,
                        ]);

                        $set('customer_id', $parseResult->detectedCustomerId);
                        $set('track_trace_nr', $parseResult->trackTraceNr);
                        $set('reference', $parseResult->reference);
                        $set('shipment_date', $parseResult->shipmentDate);
                        $set('row_count', $parseResult->rowCount());
                        self::syncImportNewCount($set, $parseResult->detectedCustomerId);
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();
                        self::notifyParseFailure($message);
                    } catch (Throwable $exception) {
                        self::notifyParseFailure($exception->getMessage());
                    }
                }),
            Group::make()
                ->extraAttributes(['class' => 'custom-form-design'])
                ->schema([
                    Placeholder::make('validation_summary')
                        ->hiddenLabel()
                        ->content(function (Get $get): HtmlString|string {
                            $result = self::resolveValidationResult($get('customer_id'));

                            if ($result === null) {
                                return '';
                            }

                            return new HtmlString(view('filament.forms.components.import-row-validation-summary', [
                                'result' => $result,
                            ])->render());
                        })
                        ->visible(fn (Get $get): bool => filled($get('row_count')) && filled($get('customer_id'))),
                    Hidden::make('row_count'),
                    Hidden::make('import_new_count'),
                    Select::make('customer_id')
                        ->label('Klant')
                        ->extraFieldWrapperAttributes(['class' => 'mt-[15px] whitespace-nowrap'])
                        ->options(fn (): array => Customer::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Customer $customer): array => [
                                $customer->id => $customer->name ?? $customer->full_name,
                            ])
                            ->all())
                        ->searchable()
                        ->required($confirmFieldsVisible)
                        ->live()
                        ->disabled(fn (): bool => ($template = self::resolveSessionTemplate()) !== null && ! $template->isUniversal())
                        ->dehydrated()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            self::syncImportNewCount($set, $state);
                        })
                        ->visible($confirmFieldsVisible),
                    TextInput::make('track_trace_nr')
                        ->label('Track & Trace nr')
                        ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                        ->maxLength(255)
                        ->visible($confirmFieldsVisible),
                    TextInput::make('reference')
                        ->label('Zending-referentie')
                        ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                        ->maxLength(255)
                        ->visible($confirmFieldsVisible),
                    DatePicker::make('shipment_date')
                        ->label('Zending-datum')
                        ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                        ->native(false)
                        ->visible($confirmFieldsVisible),
                ]),
        ]);

        $this->action(function (array $data, ProcessImportBatchAction $processImportBatch): void {
            $sessionData = session()->get(self::SESSION_KEY);

            if (! is_array($sessionData)) {
                Notification::make()
                    ->title('Import mislukt')
                    ->body('Upload een bestand en wacht tot de gegevens zijn ingelezen.')
                    ->danger()
                    ->send();

                return;
            }

            $file = self::resolveUploadedFile($data['file'] ?? null);

            $template = ImportTemplate::query()
                ->with('source.customer')
                ->findOrFail($sessionData['template_id']);

            $parseResult = new ImportParseResult(
                template: $template,
                metadata: $sessionData['metadata'],
                rows: $sessionData['rows'],
            );

            $user = auth()->user();

            $customerId = $data['customer_id'] ?? $template->source?->customer_id;

            $validation = self::resolveValidationResult($customerId);

            if ($validation === null) {
                Notification::make()
                    ->title('Import mislukt')
                    ->body('Upload een bestand en selecteer een klant.')
                    ->danger()
                    ->send();

                return;
            }

            if ($validation->newCount === 0) {
                Notification::make()
                    ->title('Geen rijen geïmporteerd')
                    ->body($validation->summaryLabel())
                    ->warning()
                    ->send();

                throw new Halt;
            }

            try {
                $result = $processImportBatch(
                    parseResult: $parseResult,
                    batchData: [
                        'customer_id' => (int) $customerId,
                        'track_trace_nr' => $data['track_trace_nr'] ?? null,
                        'reference' => $data['reference'] ?? null,
                        'shipment_date' => $data['shipment_date'] ?? null,
                    ],
                    file: $file,
                    user: $user,
                );
            } catch (RuntimeException $exception) {
                Notification::make()
                    ->title('Import mislukt')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();

                throw new Halt;
            }

            $validation = $result['validation'];

            session()->forget(self::SESSION_KEY);

            Notification::make()
                ->title('Import voltooid')
                ->body($validation->summaryLabel())
                ->success()
                ->send();
        });
    }

    private static function syncImportNewCount(Set $set, mixed $customerId): void
    {
        $result = self::resolveValidationResult($customerId);

        $set('import_new_count', $result?->newCount ?? 0);
    }

    private static function resolveSessionTemplate(): ?ImportTemplate
    {
        $sessionData = session()->get(self::SESSION_KEY);

        if (! is_array($sessionData)) {
            return null;
        }

        return ImportTemplate::query()->find($sessionData['template_id'] ?? null);
    }

    private static function resolveValidationResult(mixed $customerId): ?ImportRowValidationResult
    {
        if (blank($customerId)) {
            return null;
        }

        $template = self::resolveSessionTemplate();

        if ($template === null) {
            return null;
        }

        $sessionData = session()->get(self::SESSION_KEY);

        if (! is_array($sessionData)) {
            return null;
        }

        return app(ImportRowValidator::class)->validate(
            $template,
            (int) $customerId,
            $sessionData['rows'] ?? [],
        );
    }

    private static function clearParsedImportState(Set $set): void
    {
        session()->forget(self::SESSION_KEY);

        $set('customer_id', null);
        $set('track_trace_nr', null);
        $set('reference', null);
        $set('shipment_date', null);
        $set('row_count', null);
        $set('import_new_count', null);
    }

    private static function resolveUploadedFile(mixed $file): TemporaryUploadedFile
    {
        if ($file instanceof TemporaryUploadedFile) {
            return $file;
        }

        if ($file === null || $file === '') {
            throw new InvalidArgumentException('Geen uploadbestand gevonden.');
        }

        $unserialized = TemporaryUploadedFile::unserializeFromLivewireRequest($file);

        if ($unserialized instanceof TemporaryUploadedFile) {
            return $unserialized;
        }

        if (is_array($unserialized)) {
            foreach (Arr::flatten($unserialized) as $item) {
                if ($item instanceof TemporaryUploadedFile) {
                    return $item;
                }
            }
        }

        throw new InvalidArgumentException('Geen geldig uploadbestand gevonden.');
    }

    private static function notifyParseFailure(string $message): void
    {
        Notification::make()
            ->title('Bestand kon niet worden gelezen')
            ->body($message)
            ->danger()
            ->send();
    }
}

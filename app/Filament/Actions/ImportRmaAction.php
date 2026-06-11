<?php

namespace App\Filament\Actions;

use App\Filament\Imports\RmaImporter;
use App\Support\RmaImportFileReader;
use Filament\Actions\Action;
use Filament\Actions\Imports\Events\ImportCompleted;
use Filament\Actions\Imports\Events\ImportStarted;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ImportRmaAction extends Action
{
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
        $this->modalWidth('lg');
        $this->color('gray');

        $this->schema([
            FileUpload::make('file')
                ->label('Bestand')
                ->helperText('Excel (.xlsx) of CSV (;).')
                ->acceptedFileTypes([
                    'text/csv',
                    'text/plain',
                    'application/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->rules(['required', 'file', 'extensions:csv,txt,xlsx'])
                ->storeFiles(false)
                ->visibility('private')
                ->required()
                ->hiddenLabel(),
        ]);

        $this->action(function (array $data, RmaImportFileReader $reader): void {
            /** @var TemporaryUploadedFile $file */
            $file = $data['file'];
            $path = $file->getRealPath();
            $extension = strtolower($file->getClientOriginalExtension() ?? '');

            $rows = $reader->read($path, $extension);

            if ($rows === []) {
                throw ValidationException::withMessages([
                    'file' => 'Het bestand bevat geen importeerbare rijen.',
                ]);
            }

            $user = auth()->user();

            $import = app(Import::class);
            $import->user()->associate($user);
            $import->file_name = $file->getClientOriginalName();
            $import->file_path = $path;
            $import->importer = RmaImporter::class;
            $import->total_rows = count($rows);
            $import->save();

            $columnMap = collect(RmaImporter::getColumns())
                ->mapWithKeys(fn (ImportColumn $column): array => [$column->getName() => null])
                ->all();

            event(new ImportStarted($import, $columnMap, []));

            $failedRows = [];
            $successfulRows = 0;

            DB::transaction(function () use ($rows, $import, $columnMap, &$failedRows, &$successfulRows): void {
                foreach ($rows as $row) {
                    try {
                        (new RmaImporter($import, $columnMap, []))($row);
                        $successfulRows++;
                    } catch (ValidationException $exception) {
                        $failedRows[] = [
                            'data' => $row,
                            'validation_error' => collect($exception->errors())->flatten()->implode(' '),
                        ];
                    } catch (RowImportFailedException $exception) {
                        $failedRows[] = [
                            'data' => $row,
                            'validation_error' => $exception->getMessage(),
                        ];
                    } catch (Throwable $exception) {
                        report($exception);

                        $failedRows[] = [
                            'data' => $row,
                            'validation_error' => 'Onbekende fout tijdens importeren.',
                        ];
                    }
                }

                $import->update([
                    'processed_rows' => count($rows),
                    'successful_rows' => $successfulRows,
                ]);

                if ($failedRows !== []) {
                    $import->failedRows()->createMany($failedRows);
                }
            });

            $import->touch('completed_at');
            event(new ImportCompleted($import, $columnMap, []));

            $failedRowsCount = count($failedRows);

            $notification = Notification::make()
                ->title(RmaImporter::getCompletedNotificationTitle($import))
                ->body(RmaImporter::getCompletedNotificationBody($import))
                ->when(
                    $failedRowsCount === 0,
                    fn (Notification $notification) => $notification->success(),
                )
                ->when(
                    $failedRowsCount > 0 && $failedRowsCount < $import->total_rows,
                    fn (Notification $notification) => $notification->warning(),
                )
                ->when(
                    $failedRowsCount === $import->total_rows,
                    fn (Notification $notification) => $notification->danger(),
                );

            if ($failedRowsCount > 0) {
                $notification->body(
                    RmaImporter::getCompletedNotificationBody($import)
                    .' '
                    .trans_choice('filament-actions::import.notifications.completed.actions.download_failed_rows_csv.label', $failedRowsCount, [
                        'count' => Number::format($failedRowsCount),
                    ]),
                );
            }

            $notification->persistent()->send();
        });
    }
}

<?php

namespace App\Filament\Resources\ImportTasks\Pages;

use App\Filament\Actions\ImportRmaAction;
use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Filament\Resources\ImportTasks\Support\ImportBatchUploadedDocumentMailAttachments;
use App\Filament\Support\SalesAuthorization;
use App\Models\ImportBatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ListImportTasks extends ListRecords
{
    protected static string $resource = ImportTaskResource::class;

    protected static ?string $title = 'Importtaken';

    protected static ?string $breadcrumb = 'Importtaken';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> Used by sheet retour mail modal document upload. */
    public array $documentFiles = [];

    /** @var array<int, string> Opmerkingen per importregel in sheet retour modal (nog niet opgeslagen). */
    public array $exportRowComments = [];

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 100;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->headerActions([
                ImportRmaAction::make()
                    ->visible(fn (): bool => SalesAuthorization::canManage()),
            ])
            ->paginationPageOptions([50, 100, 250])
            ->defaultPaginationPageOption(100);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * When the user uploads files via the sheet retour modal "(toevoegen)", store them on the import batch and merge into the form checklist.
     */
    public function updatedDocumentFiles(): void
    {
        if ($this->documentFiles === []) {
            return;
        }

        $allowedMimes = config('documents.allowed_mime_types', []);
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:'.implode(',', $allowedMimes) : 'file';
        $maxKb = 10240;

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|'.$mimetypesRule.'|max:'.$maxKb,
            ]);
        } catch (ValidationException $exception) {
            $this->documentFiles = [];
            $message = $exception->validator->errors()->first();
            Notification::make()
                ->title('Ongeldige bestanden.')
                ->body($message ?: 'Controleer het bestandstype en de bestandsgrootte.')
                ->danger()
                ->send();

            return;
        }

        $batch = $this->getMountedAction()?->getRecord();
        if (! $batch instanceof ImportBatch) {
            $this->documentFiles = [];

            return;
        }

        $newMediaIds = [];
        $count = 0;
        $rejected = [];

        foreach ($this->documentFiles as $file) {
            if (! $file) {
                continue;
            }

            $mime = $file->getMimeType();
            if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();

                continue;
            }

            $media = $batch->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection(ImportBatchUploadedDocumentMailAttachments::COLLECTION);
            $newMediaIds[] = (string) $media->id;
            $count++;
        }

        $this->documentFiles = [];
        $batch->unsetRelation('media');

        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);

        if ($count > 0) {
            Notification::make()
                ->title($count === 1 ? 'Document geüpload.' : "{$count} documenten geüpload.")
                ->success()
                ->send();
        }

        if ($rejected !== []) {
            Notification::make()
                ->title('Sommige bestanden zijn overgeslagen.')
                ->body('Niet toegestaan: '.implode(', ', $rejected))
                ->warning()
                ->send();
        }
    }

    /**
     * @param  list<string>  $newMediaIds
     */
    protected function mergeNewUploadedAttachmentsIntoMountedAction(array $newMediaIds): void
    {
        if ($newMediaIds === [] || empty($this->mountedActions)) {
            return;
        }

        $index = null;
        foreach ($this->mountedActions as $key => $mounted) {
            if (! is_array($mounted)) {
                continue;
            }

            if (($mounted['name'] ?? null) === 'sendExport') {
                $index = $key;
                break;
            }

            if (isset($mounted['data']['uploaded_attachments'])) {
                $index = $key;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        if (! array_key_exists('data', $this->mountedActions[$index])) {
            $this->mountedActions[$index]['data'] = [];
        }

        $current = $this->mountedActions[$index]['data']['uploaded_attachments'] ?? [];
        $current = is_array($current) ? $current : [];
        $merged = array_values(array_unique(array_merge($current, $newMediaIds)));
        $this->mountedActions[$index]['data']['uploaded_attachments'] = $merged;
    }
}

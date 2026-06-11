<?php

namespace App\Http\Livewire;

use App\Models\Order\BaseOrder;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentsBlock extends Component
{
    use WithFileUploads;

    /** Model ID (e.g. order or fitting id). */
    public int $ownerId;

    /** Fully qualified model class (e.g. App\Models\Order\BaseOrder). */
    public string $ownerClass;

    /** Media collection name. */
    public string $collection = 'documents';
    public string $info = '';

    /** Allowed MIME types for upload; empty means use config default. */
    public array $allowedMimeTypes = [];

    /** Optional: unique key for the upload zone (for multiple blocks on one page). */
    public string $uploadZoneKey = 'default';

    /** Optional: HTML id for the section (e.g. card-docs, card-fitting-documenten). */
    public ?string $sectionId = null;

    /** Optional: card title (e.g. "Documenten" or "Afbeeldingen"). */
    public string $blockTitle = 'Documenten';

    /** Optional: accept attribute for file input (e.g. images only); empty uses config default. */
    public ?string $acceptAttributeOverride = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $documentFiles = [];

    /** Openable media IDs (synced with frontend so modal works after upload). */
    public array $openableIds = [];

    /** Openable documents as [['media_id' => int, 'uid' => string], ...] for footer display. */
    public array $openableDocuments = [];

    /** Max file size per file in KB (default 10MB). */
    public int $maxFileSizeKb = 10240;

    public bool $readOnly = false;

    public function mount(
        int $ownerId,
        string $ownerClass,
        string $collection = 'documents',
        string $info = '',
        array $allowedMimeTypes = [],
        string $uploadZoneKey = 'default',
        ?string $sectionId = null,
        string $blockTitle = 'Documenten',
        ?string $acceptAttributeOverride = null,
        bool $readOnly = false,
    ): void {
        $this->ownerId = $ownerId;
        $this->ownerClass = $ownerClass;
        $this->collection = $collection;
        $this->info = $info;
        $this->allowedMimeTypes = $allowedMimeTypes !== [] ? $allowedMimeTypes : config('documents.allowed_mime_types', []);
        $this->uploadZoneKey = $uploadZoneKey;
        $this->sectionId = $sectionId;
        $this->blockTitle = $blockTitle;
        $this->acceptAttributeOverride = $acceptAttributeOverride;
        $this->readOnly = $readOnly;
    }

    protected function getOwner(): ?Model
    {
        if (! is_subclass_of($this->ownerClass, Model::class)) {
            return null;
        }

        if (is_subclass_of($this->ownerClass, BaseOrder::class)) {
            return $this->ownerClass::withoutGlobalScopes()->find($this->ownerId);
        }

        return $this->ownerClass::find($this->ownerId);
    }

    public function getUploadedDocuments(): array
    {
        $owner = $this->getOwner();
        if ($owner === null) {
            return [];
        }

        $openableMimes = config('documents.openable_mime_types', []);
        $openableExtensions = array_map(
            static fn (mixed $extension): string => mb_strtolower((string) $extension),
            config('documents.openable_extensions', []),
        );

        return $owner->getMedia($this->collection)
            ->map(fn ($media) => [
                'id' => 'media-' . $media->id,
                'media_id' => $media->id,
                'type' => 'uploaded',
                'uid' => $media->file_name,
                'sent_at' => $media->created_at,
                'modal' => null,
                'mime_type' => $media->mime_type,
                'extension' => mb_strtolower((string) ($media->extension ?? '')),
                'openable' => in_array($media->mime_type, $openableMimes, true)
                    || in_array(mb_strtolower((string) ($media->extension ?? '')), $openableExtensions, true),
                'is_readonly' => (bool) ($media->getCustomProperty('readonly') ?? $media->getCustomProperty('readyonly') ?? false),
            ])
            ->sortByDesc(fn ($doc) => $doc['sent_at'])
            ->values()
            ->all();
    }

    public function downloadDocument(int $mediaId): ?StreamedResponse
    {
        $owner = $this->getOwner();
        if ($owner === null) {
            return null;
        }

        $media = $owner->getMedia($this->collection)->firstWhere('id', $mediaId);
        if ($media === null) {
            return null;
        }

        $relativePath = $media->getPathRelativeToRoot();
        if (! Storage::disk($media->disk)->exists($relativePath)) {
            return null;
        }

        $filename = $media->file_name
            ?: ($media->name ? $media->name . '.' . $media->extension : 'document-' . $media->id . '.' . $media->extension);

        return Storage::disk($media->disk)->download($relativePath, $filename);
    }

    public function deleteDocument(int $mediaId): void
    {
        if ($this->readOnly) {
            return;
        }

        $owner = $this->getOwner();
        if ($owner === null) {
            return;
        }

        $media = $owner->getMedia($this->collection)->firstWhere('id', $mediaId);
        if ($media === null) {
            return;
        }

        $isReadonly = (bool) ($media->getCustomProperty('readonly') ?? $media->getCustomProperty('readyonly') ?? false);
        if ($isReadonly) {
            Notification::make()
                ->title('Dit document kan niet verwijderd worden.')
                ->warning()
                ->send();

            return;
        }

        $media->delete();

        $this->dispatch('uploaded-docs-changed');

        Notification::make()
            ->title('Document verwijderd.')
            ->success()
            ->send();
    }

    public function updatedDocumentFiles(): void
    {
        if ($this->readOnly || empty($this->documentFiles)) {
            return;
        }

        $allowedMimes = $this->allowedMimeTypes;
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:' . implode(',', $allowedMimes) : 'file';

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|' . $mimetypesRule . '|max:' . $this->maxFileSizeKb,
            ]);
        } catch (ValidationException $e) {
            $this->documentFiles = [];
            $message = $e->validator->errors()->first();
            Notification::make()
                ->title('Ongeldige bestanden.')
                ->body($message ?: 'Controleer het bestandstype en de bestandsgrootte.')
                ->danger()
                ->send();

            return;
        }

        $owner = $this->getOwner();
        if ($owner === null) {
            $this->documentFiles = [];
            Notification::make()
                ->title('Model niet gevonden.')
                ->danger()
                ->send();
            return;
        }

        $allowedMimes = $this->allowedMimeTypes;
        $count = 0;
        $rejected = [];
        $newMediaIds = [];

        $existingFileNames = $owner->getMedia($this->collection)->pluck('file_name')->toArray();

        foreach ($this->documentFiles as $file) {
            if (! $file) {
                continue;
            }

            $mime = $file->getMimeType();
            if (! in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }

            $uniqueName = $this->resolveUniqueFileName($file->getClientOriginalName(), $existingFileNames);

            $media = $owner->addMedia($file->getRealPath())
                ->usingFileName($uniqueName)
                ->toMediaCollection($this->collection);
            $newMediaIds[] = (string) $media->id;
            $count++;
        }

        $this->documentFiles = [];
        $owner->unsetRelation('media');

        $this->dispatch('uploaded-docs-changed');
        $this->dispatch('documents-uploaded', newMediaIds: $newMediaIds);

        if ($count > 0) {
            Notification::make()
                ->title($count === 1 ? 'Document geüpload.' : "{$count} documenten geüpload.")
                ->success()
                ->send();
        }

        if ($rejected !== []) {
            $names = implode(', ', array_slice($rejected, 0, 5));
            if (count($rejected) > 5) {
                $names .= ' … (+' . (count($rejected) - 5) . ' meer)';
            }
            Notification::make()
                ->title('Bestandstype niet toegestaan.')
                ->body('Alleen documenten (Word, Excel, enz.), PDF en mailbestanden zijn toegestaan. Overgeslagen: ' . $names)
                ->danger()
                ->send();
        }
    }

    public function getPreviewUrl(int $mediaId): string
    {
        return route('documents.media-preview', ['id' => $mediaId]);
    }

    #[On('postnl-label-created')]
    #[On('delivery-note-saved')]
    public function refreshDocuments(): void
    {
        $this->getOwner()?->unsetRelation('media');
    }

    /**
     * Ensure the filename is unique within the collection by appending -(1), -(2), etc.
     * Applies the same space-to-dash sanitization as Spatie MediaLibrary so comparisons
     * against stored file_name values are accurate.
     * Tracks newly generated names in $existingNames so multiple uploads in one batch
     * also get distinct names.
     *
     * @param  string[]  $existingNames  Passed by reference so each call updates the list.
     */
    private function resolveUniqueFileName(string $fileName, array &$existingNames): string
    {
        $fileName = str_replace(['#', '/', '\\', ' '], '-', $fileName);

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName  = $extension !== ''
            ? substr($fileName, 0, -(strlen($extension) + 1))
            : $fileName;

        $candidate = $fileName;
        $counter   = 1;

        while (in_array($candidate, $existingNames, true)) {
            $candidate = $extension !== ''
                ? "{$baseName}-({$counter}).{$extension}"
                : "{$baseName}-({$counter})";
            $counter++;
        }

        $existingNames[] = $candidate;

        return $candidate;
    }

    public function render(): View
    {
        $documents = $this->getUploadedDocuments();
        $openableIds = [];
        $openableDocuments = [];
        foreach ($documents as $doc) {
            if (! empty($doc['openable'])) {
                $openableIds[] = $doc['media_id'];
                $openableDocuments[] = [
                    'media_id' => $doc['media_id'],
                    'uid' => $doc['uid'],
                    'mime_type' => $doc['mime_type'],
                    'extension' => $doc['extension'],
                ];
            }
        }
        $this->openableIds = $openableIds;
        $this->openableDocuments = $openableDocuments;

        return view('livewire.documents-block', [
            'documents' => $documents,
            'acceptAttribute' => $this->acceptAttributeOverride ?? config('documents.accept_attribute', ''),
            'previewUrlTemplate' => url()->route('documents.media-preview', ['id' => 0]),
        ]);
    }
}

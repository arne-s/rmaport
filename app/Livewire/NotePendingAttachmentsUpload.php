<?php

namespace App\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotePendingAttachmentsUpload extends Component
{
    use WithFileUploads;

    public string $bucket = '';

    /** @var array<int, mixed> */
    public array $documentFiles = [];

    public int $maxFileSizeKb = 10240;

    protected function cacheKey(): string
    {
        return 'note_pending_attachments.'.$this->bucket;
    }

    /**
     * Normalize cache payload (legacy: list of path strings; current: list of {path, original_name}).
     *
     * @param  mixed  $raw
     * @return list<array{path: string, original_name: string}>
     */
    public static function normalizeCachePayload(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = [
                    'path' => $item,
                    'original_name' => self::displayNameFromStoredFilename($item),
                ];

                continue;
            }

            if (is_array($item) && isset($item['path']) && is_string($item['path']) && $item['path'] !== '') {
                $original = $item['original_name'] ?? null;
                $out[] = [
                    'path' => $item['path'],
                    'original_name' => is_string($original) && $original !== ''
                        ? $original
                        : self::displayNameFromStoredFilename($item['path']),
                ];
            }
        }

        return $out;
    }

    public static function displayNameFromStoredFilename(string $relativePath): string
    {
        $base = basename($relativePath);
        $stripped = preg_replace('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}_/i', '', $base);

        return ($stripped !== null && $stripped !== '') ? $stripped : $base;
    }

    /**
     * @return list<array{path: string, original_name: string}>
     */
    protected function getStoredEntries(): array
    {
        if ($this->bucket === '') {
            return [];
        }

        return self::normalizeCachePayload(Cache::get($this->cacheKey(), []));
    }

    /**
     * @param  list<array{path: string, original_name: string}>  $entries
     */
    protected function putStoredEntries(array $entries): void
    {
        if ($this->bucket === '') {
            return;
        }

        Cache::put($this->cacheKey(), array_values($entries), now()->addHours(2));
    }

    public function updatedDocumentFiles(): void
    {
        if ($this->bucket === '') {
            $this->documentFiles = [];

            return;
        }

        if ($this->documentFiles === []) {
            return;
        }

        $allowedMimes = config('documents.allowed_mime_types', []);
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:'.implode(',', $allowedMimes) : 'file';

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|'.$mimetypesRule.'|max:'.$this->maxFileSizeKb,
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

        $entries = $this->getStoredEntries();
        $rejected = [];

        foreach ($this->documentFiles as $file) {
            if (! $file) {
                continue;
            }

            $mime = $file->getMimeType();
            if (! in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();

                continue;
            }

            $originalName = $file->getClientOriginalName();
            $safeName = Str::uuid()->toString().'_'.$originalName;
            $path = $file->storeAs('notes-temp', $safeName, 'public');
            $entries[] = [
                'path' => $path,
                'original_name' => $originalName,
            ];
        }

        $this->documentFiles = [];
        $this->putStoredEntries($entries);

        if ($rejected !== []) {
            $names = implode(', ', array_slice($rejected, 0, 5));
            if (count($rejected) > 5) {
                $names .= ' … (+'.(count($rejected) - 5).' meer)';
            }
            Notification::make()
                ->title('Bestandstype niet toegestaan.')
                ->body('Overgeslagen: '.$names)
                ->danger()
                ->send();
        }
    }

    public function removeAtIndex(int $index): void
    {
        $entries = $this->getStoredEntries();
        if (! isset($entries[$index])) {
            return;
        }

        $relative = $entries[$index]['path'];
        $disk = Storage::disk('public');
        if ($disk->exists($relative)) {
            $disk->delete($relative);
        }

        array_splice($entries, $index, 1);
        $this->putStoredEntries($entries);
    }

    public function downloadPath(int $index): ?StreamedResponse
    {
        $entries = $this->getStoredEntries();
        if (! isset($entries[$index])) {
            return null;
        }

        $relative = $entries[$index]['path'];
        $filename = $entries[$index]['original_name'];
        $disk = Storage::disk('public');
        if (! $disk->exists($relative)) {
            return null;
        }

        return $disk->download($relative, $filename);
    }

    /**
     * @return array<int, array{id: int, uid: string, sent_at: Carbon, openable: bool, mime_type: string|null, public_url: string|null}>
     */
    public function getRows(): array
    {
        $openableMimes = config('documents.openable_mime_types', []);
        $rows = [];
        foreach ($this->getStoredEntries() as $index => $entry) {
            $relative = $entry['path'];
            $disk = Storage::disk('public');
            if (! $disk->exists($relative)) {
                continue;
            }
            $mime = $disk->mimeType($relative);
            if ($mime === false) {
                continue;
            }
            $openable = in_array($mime, $openableMimes, true);
            $uid = $entry['original_name'];
            $rows[] = [
                'id' => $index,
                'uid' => $uid,
                'sent_at' => Carbon::createFromTimestamp($disk->lastModified($relative)),
                'openable' => $openable,
                'mime_type' => $mime,
                'public_url' => $openable ? $disk->url($relative) : null,
            ];
        }

        return $rows;
    }

    public function render(): View
    {
        return view('livewire.note-pending-attachments-upload', [
            'documents' => $this->getRows(),
            'acceptAttribute' => config('documents.accept_attribute', ''),
        ]);
    }
}

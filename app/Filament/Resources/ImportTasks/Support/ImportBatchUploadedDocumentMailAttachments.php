<?php

namespace App\Filament\Resources\ImportTasks\Support;

use App\Models\ImportBatch;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ImportBatchUploadedDocumentMailAttachments
{
    public const COLLECTION = 'documents';

    /**
     * @return array<string, string> Media id => file label
     */
    public static function attachmentOptions(ImportBatch $batch): array
    {
        $options = [];

        foreach (self::attachableMediaForBatch($batch) as $media) {
            $options[(string) $media->id] = self::mediaCheckboxLabel($media);
        }

        return $options;
    }

    public static function mediaCheckboxLabel(Media $media): string
    {
        return $media->file_name ?: ($media->name
            ? $media->name.'.'.$media->extension
            : 'document-'.$media->id.'.'.$media->extension);
    }

    public static function mediaBelongsToBatch(Media $media, int $importBatchId): bool
    {
        if ($media->collection_name !== self::COLLECTION) {
            return false;
        }

        $owner = $media->model;

        return $owner instanceof ImportBatch
            && (int) $owner->getKey() === $importBatchId;
    }

    /**
     * @param  list<int|string>  $mediaIds
     */
    public static function attachToMailable(Mailable $mail, int $importBatchId, array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            $media = Media::find($mediaId);
            if ($media === null || ! self::mediaBelongsToBatch($media, $importBatchId)) {
                continue;
            }

            $path = $media->getPathRelativeToRoot();
            if (! Storage::disk($media->disk)->exists($path)) {
                continue;
            }

            $content = Storage::disk($media->disk)->get($path);
            if ($content === null) {
                continue;
            }

            $mail->attachData(
                $content,
                self::mediaCheckboxLabel($media),
                ['mime' => $media->mime_type ?? 'application/octet-stream']
            );
        }
    }

    /**
     * @return Collection<int, Media>
     */
    private static function attachableMediaForBatch(ImportBatch $batch): Collection
    {
        return $batch->getMedia(self::COLLECTION)
            ->sortByDesc('created_at')
            ->values();
    }
}

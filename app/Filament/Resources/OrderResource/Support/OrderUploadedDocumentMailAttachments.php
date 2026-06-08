<?php

namespace App\Filament\Resources\OrderResource\Support;

use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Uploaded files from "Documenten en Afbeelding(en)" blocks on an aanvraag (mail modal checklist).
 */
final class OrderUploadedDocumentMailAttachments
{
    /**
     * Media collections on {@see Main} used by tab document blocks.
     *
     * @var list<string>
     */
    private const MAIN_UPLOAD_COLLECTIONS = [
        'fitting_documents',
        'product_documents',
        'assembly_documents',
        'delivery_documents',
        'service_documents',
    ];

    /**
     * Media collections on any {@see BaseOrder} (main and children).
     *
     * @var list<string>
     */
    private const ORDER_UPLOAD_COLLECTIONS = [
        'documents',
        'images',
    ];

    /**
     * @return array<string, string> Media id => file label
     */
    public static function attachmentOptions(BaseOrder $record): array
    {
        $options = [];

        foreach (self::attachableMediaForRecord($record) as $media) {
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

    public static function isAttachableCollection(string $collectionName): bool
    {
        return in_array($collectionName, self::MAIN_UPLOAD_COLLECTIONS, true)
            || in_array($collectionName, self::ORDER_UPLOAD_COLLECTIONS, true);
    }

    public static function mediaBelongsToMain(Media $media, int $mainId): bool
    {
        if (! self::isAttachableCollection($media->collection_name)) {
            return false;
        }

        $owner = $media->model;
        if (! $owner instanceof BaseOrder) {
            return false;
        }

        $mediaMain = $owner->getMain() ?? $owner;

        return (int) $mediaMain->getKey() === $mainId;
    }

    /**
     * @param  list<int>  $mediaIds
     */
    public static function attachToMailable(Mailable $mail, int $mainId, array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            $media = Media::find($mediaId);
            if ($media === null || ! self::mediaBelongsToMain($media, $mainId)) {
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
    private static function attachableMediaForRecord(BaseOrder $record): Collection
    {
        $main = OrderCustomerMailRecipients::documentOwnerForRecord($record);
        if (! $main instanceof Main) {
            return collect();
        }

        $media = collect();

        foreach (self::ORDER_UPLOAD_COLLECTIONS as $collection) {
            $media = $media->merge($main->getMedia($collection));
        }

        foreach (self::MAIN_UPLOAD_COLLECTIONS as $collection) {
            $media = $media->merge($main->getMedia($collection));
        }

        foreach (self::relatedOrdersForMain($main) as $order) {
            if ((int) $order->getKey() === (int) $main->getKey()) {
                continue;
            }

            foreach (self::ORDER_UPLOAD_COLLECTIONS as $collection) {
                $media = $media->merge($order->getMedia($collection));
            }
        }

        return $media
            ->unique('id')
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * @return list<BaseOrder>
     */
    private static function relatedOrdersForMain(Main $main): array
    {
        return BaseOrder::withoutGlobalScopes()
            ->where('main_id', $main->getId())
            ->get()
            ->all();
    }
}

<?php

namespace App\Models;

use App\Models\Order\Main;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PurchaseOrderInvoice extends Model implements HasMedia
{
    use InteractsWithMedia;
    protected $fillable = [
        'orderable_type',
        'orderable_id',
        'main_id',
        'exact_id',
        'exact_synced_at',
        'exact_error_at',
        'exact_error_message',
        'paid_at',
        'description',
        'amount',
        'vat_amount',
        'total_amount_inc_vat',
        'currency',
        'entry_date',
        'due_date',
        'invoice_number',
        'supplier_name',
        'document_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount_inc_vat' => 'decimal:2',
            'entry_date' => 'date',
            'due_date' => 'date',
            'exact_synced_at' => 'datetime',
            'exact_error_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function orderable(): MorphTo
    {
        return $this->morphTo();
    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(Main::class, 'main_id');
    }

    public function purchaseOrder(): ?PurchaseOrder
    {
        return $this->orderable instanceof PurchaseOrder ? $this->orderable : null;
    }

    public function activePurchaseOrder(): ?PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrder();

        if ($purchaseOrder === null || ! $purchaseOrder->isLinkable()) {
            return null;
        }

        return $purchaseOrder;
    }

    public function isLinkedToPurchaseOrder(): bool
    {
        return $this->purchaseOrder() instanceof PurchaseOrder;
    }

    public function isLinkedToActivePurchaseOrder(): bool
    {
        return $this->activePurchaseOrder() instanceof PurchaseOrder;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
    }

    public function getDaysSinceReceivedAttribute(): ?int
    {
        if ($this->entry_date === null) {
            return null;
        }

        return abs((int) ($this->paid_at ?? now())->diffInDays($this->entry_date));
    }

    public function getIsLateAttribute(): bool
    {
        return $this->due_date !== null
            && $this->paid_at === null
            && $this->due_date->isPast();
    }

    public function isExactSynced(): bool
    {
        return filled($this->exact_id);
    }

    public function isPendingExactSync(): bool
    {
        return blank($this->exact_id) && $this->exact_error_at === null;
    }

    public function hasExactSyncError(): bool
    {
        return $this->exact_error_at !== null && blank($this->exact_id);
    }

    public function exactSyncStatusLabel(): ?string
    {
        if ($this->hasExactSyncError()) {
            return 'Synchronisatiefout Exact';
        }

        if ($this->isPendingExactSync()) {
            return 'Wacht op synchronisatie Exact';
        }

        if ($this->isExactSynced()) {
            return 'Gesynchroniseerd met Exact';
        }

        return null;
    }

    /**
     * Human-readable label for document lists (inkooporder documentenblok).
     */
    public function documentListLabel(?PurchaseOrder $purchaseOrder = null): string
    {
        $purchaseOrder ??= $this->purchaseOrder();

        if (filled($this->invoice_number) && ! self::isInternalDocumentReference($this->invoice_number)) {
            return (string) $this->invoice_number;
        }

        $media = $this->resolveLinkedMedia($purchaseOrder);

        if ($media instanceof Media) {
            $customNumber = $media->getCustomProperty('invoice_number');
            if (is_string($customNumber) && filled($customNumber) && ! self::isInternalDocumentReference($customNumber)) {
                return $customNumber;
            }

            if (filled($media->name) && ! self::isInternalDocumentReference($media->name)) {
                return (string) $media->name;
            }
        }

        if ($purchaseOrder?->reference_number) {
            return 'Factuur · ' . $purchaseOrder->reference_number;
        }

        if (filled($this->description) && $this->description !== 'Handmatig geüpload') {
            return (string) $this->description;
        }

        return 'Inkoopfactuur';
    }

    public static function orphanMediaListLabel(Media $media): string
    {
        $customNumber = $media->getCustomProperty('invoice_number');
        if (is_string($customNumber) && filled($customNumber) && ! self::isInternalDocumentReference($customNumber)) {
            return $customNumber;
        }

        if (filled($media->name) && ! self::isInternalDocumentReference($media->name)) {
            return (string) $media->name;
        }

        $baseName = pathinfo((string) $media->file_name, PATHINFO_FILENAME);
        if (filled($baseName) && ! self::isInternalDocumentReference($baseName)) {
            return $baseName;
        }

        return 'Inkoopfactuur';
    }

    public static function isInternalDocumentReference(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return true;
        }

        if (str_starts_with($value, 'media-library')) {
            return true;
        }

        if (str_starts_with($value, 'manual-')) {
            return true;
        }

        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1) {
            return true;
        }

        return false;
    }

    public static function isPreviewableDocumentMedia(?Media $media): bool
    {
        if (! $media instanceof Media) {
            return false;
        }

        $path = $media->getPath();

        if (! is_file($path)) {
            return false;
        }

        if ((int) $media->size < 50) {
            return false;
        }

        $header = @file_get_contents($path, false, null, 0, 5);

        if ($header === false || ! str_starts_with($header, '%PDF')) {
            return false;
        }

        return true;
    }

    /**
     * Remove broken or placeholder media rows so Exact import can attach a fresh PDF.
     */
    public function purgeInvalidLinkedDocumentMedia(?PurchaseOrder $purchaseOrder = null): void
    {
        foreach ($this->getMedia('documents') as $media) {
            if (! self::isPreviewableDocumentMedia($media)) {
                $media->delete();
            }
        }

        $purchaseOrder ??= $this->purchaseOrder();

        if ($purchaseOrder === null) {
            return;
        }

        $purchaseOrder->loadMissing('media');

        foreach ($purchaseOrder->getMedia('documents') as $media) {
            $linkedId = $media->getCustomProperty('purchase_order_invoice_id');

            if ($linkedId !== null
                && (int) $linkedId === (int) $this->getKey()
                && ! self::isPreviewableDocumentMedia($media)) {
                $media->delete();
            }
        }
    }

    public function resolveLinkedMedia(?PurchaseOrder $purchaseOrder = null): ?Media
    {
        $ownMedia = $this->getMedia('documents')->first(
            fn (Media $media): bool => self::isPreviewableDocumentMedia($media),
        );

        if ($ownMedia instanceof Media) {
            return $ownMedia;
        }

        $purchaseOrder ??= $this->purchaseOrder();

        if ($purchaseOrder === null) {
            return null;
        }

        $purchaseOrder->loadMissing('media');

        return $purchaseOrder->getMedia('documents')->first(function (Media $media): bool {
            $linkedId = $media->getCustomProperty('purchase_order_invoice_id');

            return $linkedId !== null
                && (int) $linkedId === (int) $this->getKey()
                && self::isPreviewableDocumentMedia($media);
        });
    }

    public function getPdfAbsolutePath(): ?string
    {
        $media = $this->resolveLinkedMedia();

        if (! $media instanceof Media) {
            return null;
        }

        $path = $media->getPath();

        return is_file($path) ? $path : null;
    }
}

<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property string $uid
 * @property string|null $reference
 * @property string|null $order_nr
 * @property string|null $barcode
 * @property string|null $defect_id
 * @property string|null $global_id
 * @property int $quantity
 * @property string|null $ean
 * @property string|null $article_number
 * @property ProductBrand|null $brand
 * @property string|null $product_group
 * @property string|null $product_name
 * @property string|null $serial_number
 * @property string|null $imei
 * @property string|null $product_condition
 * @property string|null $graded_type
 * @property string|null $accessories
 * @property string|null $return_reason
 * @property string|null $return_sub_reason
 * @property string|null $location_name
 * @property string|null $location_code
 * @property string|null $external_location_id
 * @property string|null $language
 * @property Carbon|null $purchased_at
 * @property string|null $packing_slip_number
 * @property PaymentMethod|null $payment_method
 * @property string|null $complaint
 * @property string|null $service
 * @property string|null $notes
 * @property RmaStatus $status
 * @property bool $is_draft
 * @property bool $reminder
 * @property bool $is_warranty
 * @property bool $is_processed
 * @property bool $is_refurbish
 * @property bool $is_doa
 * @property bool $is_invoiced
 * @property Carbon|null $received_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 */
class Rma extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'rmas';

    protected $fillable = [
        'customer_id',
        'uid',
        'reference',
        'order_nr',
        'barcode',
        'defect_id',
        'global_id',
        'quantity',
        'ean',
        'article_number',
        'brand',
        'product_group',
        'product_name',
        'serial_number',
        'imei',
        'product_condition',
        'graded_type',
        'accessories',
        'return_reason',
        'return_sub_reason',
        'location_name',
        'location_code',
        'external_location_id',
        'language',
        'purchased_at',
        'packing_slip_number',
        'payment_method',
        'complaint',
        'service',
        'notes',
        'status',
        'is_draft',
        'reminder',
        'is_warranty',
        'is_processed',
        'is_refurbish',
        'is_doa',
        'is_invoiced',
        'received_at',
        'reminded_at',
        'processed_at',
        'returned_at',
    ];

    protected $attributes = [
        'status' => 'open',
        'quantity' => 1,
        'reminder' => false,
        'is_warranty' => false,
        'is_processed' => false,
        'is_refurbish' => false,
        'is_doa' => false,
        'is_invoiced' => false,
        'is_draft' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => RmaStatus::class,
            'payment_method' => PaymentMethod::class,
            'brand' => ProductBrand::class,
            'quantity' => 'integer',
            'is_draft' => 'boolean',
            'reminder' => 'boolean',
            'is_warranty' => 'boolean',
            'is_processed' => 'boolean',
            'is_refurbish' => 'boolean',
            'is_doa' => 'boolean',
            'is_invoiced' => 'boolean',
            'purchased_at' => 'date',
            'received_at' => 'datetime',
            'reminded_at' => 'datetime',
            'processed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('rma_documents');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rmaEvents(): HasMany
    {
        return $this->hasMany(RmaEvent::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(RmaStatusChange::class);
    }

    public function linkedNotes(): MorphToMany
    {
        return $this->morphToMany(Note::class, 'model', 'model_has_notes');
    }

    public function changeStatus(RmaStatus $to, ?int $userId = null): void
    {
        $from = $this->status;
        if ($from === $to) {
            return;
        }

        $userId ??= Auth::id();

        RmaStatusChange::query()->create([
            'rma_id' => $this->getKey(),
            'from_status' => $from->value,
            'to_status' => $to->value,
            'changed_by' => $userId,
        ]);

        $fromLabel = $from->getLabel() ?? $from->value;
        $toLabel = $to->getLabel() ?? $to->value;

        $this->rmaEvents()->create([
            'type' => "RMA-status gewijzigd: {$fromLabel} → {$toLabel}",
            'user_id' => $userId,
        ]);

        $this->status = $to;
        $this->save();
    }

    public function logEvent(string $type, ?array $data = null, ?int $userId = null): RmaEvent
    {
        return $this->rmaEvents()->create([
            'type' => $type,
            'data' => $data,
            'user_id' => $userId ?? Auth::id(),
        ]);
    }

    public static function createDraft(): self
    {
        /** @var self $rma */
        $rma = static::query()->create([
            'uid' => static::generateDraftUid(),
            'status' => RmaStatus::Open,
            'is_draft' => true,
        ]);

        return $rma;
    }

    public static function generateDraftUid(): string
    {
        do {
            $uid = 'DR-'.strtoupper(Str::random(17));
        } while (static::query()->where('uid', $uid)->exists());

        return $uid;
    }
}

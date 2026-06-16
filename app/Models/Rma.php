<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\RmaAssessment;
use App\Enums\RmaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use App\Support\RmaNumberSequence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $import_row_id
 * @property int|null $product_id
 * @property string $uid
 * @property int $quantity
 * @property string|null $accessories
 * @property string|null $return_reason
 * @property string|null $packing_slip_number
 * @property PaymentMethod|null $payment_method
 * @property string|null $complaint
 * @property RmaAssessment|null $assessment
 * @property string|null $service
 * @property string|null $notes
 * @property RmaStatus $status
 * @property bool $is_draft
 * @property bool $reminder
 * @property bool $is_warranty
 * @property bool $is_processed
 * @property bool $is_refurbish
 * @property bool $is_invoiced
 * @property Carbon|null $received_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Customer|null $customer
 * @property-read ImportRow|null $importRow
 * @property-read Product|null $product
 */
class Rma extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'rmas';

    protected $fillable = [
        'customer_id',
        'import_row_id',
        'product_id',
        'uid',
        'quantity',
        'accessories',
        'return_reason',
        'packing_slip_number',
        'payment_method',
        'complaint',
        'assessment',
        'service',
        'notes',
        'status',
        'is_draft',
        'reminder',
        'is_warranty',
        'is_processed',
        'is_refurbish',
        'is_invoiced',
        'received_at',
        'reminded_at',
        'processed_at',
    ];

    protected $attributes = [
        'status' => 'open',
        'quantity' => 1,
        'reminder' => false,
        'is_warranty' => false,
        'is_processed' => false,
        'is_refurbish' => false,
        'is_invoiced' => false,
        'is_draft' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => RmaStatus::class,
            'payment_method' => PaymentMethod::class,
            'assessment' => RmaAssessment::class,
            'quantity' => 'integer',
            'is_draft' => 'boolean',
            'reminder' => 'boolean',
            'is_warranty' => 'boolean',
            'is_processed' => 'boolean',
            'is_refurbish' => 'boolean',
            'is_invoiced' => 'boolean',
            'received_at' => 'datetime',
            'reminded_at' => 'datetime',
            'processed_at' => 'datetime',
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

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
            'uid' => static::generateNextUid(),
            'status' => RmaStatus::Open,
            'is_draft' => true,
        ]);

        return $rma;
    }

    public static function generateNextUid(): string
    {
        return DB::transaction(fn (): string => RmaNumberSequence::next());
    }
}

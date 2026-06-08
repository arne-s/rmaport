<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\ReleaseOrderStatus;
use App\Models\Concerns\FormatsDeliveryAddressLine;
use App\Models\Order\BaseOrder;
use App\Models\Customer;
use App\Models\Order\Main;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string $reference_number
 * @property int|null $order_id
 * @property int|null $main_id
 * @property int|null $quote_id
 * @property int $dealer_id
 * @property int|null $author_id
 * @property ReleaseOrderStatus|null $status
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property bool|null $is_cancelled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Customer $dealer
 * @property-read Main|null $main
 * @property-read Collection<int, OrderProduct> $orderProducts
 * @property-read User|null $author
 */
class ReleaseOrder extends Model implements HasMedia
{
    use FormatsDeliveryAddressLine;
    use InteractsWithMedia;

    protected $table = 'release_orders';

    protected $attributes = [
        'status' => 'initial',
    ];

    protected $fillable = [
        'reference_number',
        'order_id',
        'main_id',
        'quote_id',
        'dealer_id',
        'author_id',
        'status',
        'sent_at',
        'is_cancelled',
        'additional',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReleaseOrderStatus::class,
            'sent_at' => 'datetime',
            'additional' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (ReleaseOrder $record): void {
            if (! $record->wasChanged('status')) {
                return;
            }

            $to = $record->status;
            $from = $record->getOriginal('status');

            $fromStr = $from instanceof ReleaseOrderStatus ? $from->value : $from;
            $toStr = $to instanceof ReleaseOrderStatus ? $to->value : $to;

            ReleaseOrderStatusChange::create([
                'release_order_id' => $record->id,
                'from_status' => $fromStr,
                'to_status' => $toStr,
                'changed_by' => auth()?->id(),
                'meta' => null,
            ]);

            if ($record->orderProductsAreAllInPickState()) {
                return;
            }

            $record->applyStatusToOrderProducts();
        });
    }

    public function getAdditional(): ?array
    {
        return $this->additional;
    }

    public function setAdditional(?array $value): self
    {
        $this->additional = $value;

        return $this;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BaseOrder::class);
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'dealer_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function getAuthorId(): ?int
    {
        return $this->author_id;
    }

    public function setAuthorId(?int $value): self
    {
        $this->author_id = $value;

        return $this;
    }

    public function orderProducts(): HasMany
    {
        return $this->hasMany(OrderProduct::class, 'release_order_id')->orderBy('sort');
    }

    public function hasLinkedOrderProducts(): bool
    {
        if ($this->relationLoaded('orderProducts')) {
            return $this->orderProducts->isNotEmpty();
        }

        return $this->orderProducts()->exists();
    }

    public function orderProductsAreAllPickedReceived(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $picked = OrderProductStatus::PickedReceived->value;

        return $this->orderProducts()->exists()
            && ! $this->orderProducts()->where('status', '!=', $picked)->exists();
    }

    /**
     * When true, syncing release header → lines (applyStatusToOrderProducts) should not run: every line is already gepickt.
     */
    public function orderProductsAreAllInPickState(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $pickValues = [
            OrderProductStatus::PickedReceived->value,
            OrderProductStatus::PickedStock->value,
        ];

        return $this->orderProducts()->exists()
            && ! $this->orderProducts()->whereNotIn('status', $pickValues)->exists();
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(ReleaseOrderStatusChange::class);
    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(Main::class, 'main_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
    }

    /**
     * @return array{companyPurchasePrice: float, companySalesPrice: float}
     */
    public function calculatePriceTotals(): array
    {
        $totals = [
            'companyPurchasePrice' => 0.0,
            'companySalesPrice' => 0.0,
        ];

        foreach ($this->orderProducts as $orderProduct) {
            $totals['companyPurchasePrice'] += $orderProduct->getCompanyPurchasePriceTotal();
            $totals['companySalesPrice'] += $orderProduct->getCompanySalesPriceTotal();
        }

        return $totals;
    }

    public function getPriceTotalsAttribute(): array
    {
        return $this->calculatePriceTotals();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getReferenceNumber(): string
    {
        return $this->reference_number;
    }

    public function setReferenceNumber(string $referenceNumber): self
    {
        $this->reference_number = $referenceNumber;
        return $this;
    }

    public function getOrderId(): ?int
    {
        return $this->order_id;
    }

    public function setOrderId(?int $orderId): self
    {
        $this->order_id = $orderId;
        return $this;
    }

    public function getDealerId(): int
    {
        return $this->dealer_id;
    }

    public function setDealerId(int $dealerId): self
    {
        $this->dealer_id = $dealerId;
        return $this;
    }

    public function getStatus(): ?ReleaseOrderStatus
    {
        return $this->status;
    }

    public function setStatus(?ReleaseOrderStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrderProductStatus|null>  $lines
     */
    public static function deriveReleaseOrderStatusFromOrderProductLineStatuses(\Illuminate\Support\Collection $lines): ReleaseOrderStatus
    {
        $raw = $lines->filter(fn (?OrderProductStatus $s): bool => $s !== null)->values();
        if ($raw->isEmpty()) {
            return ReleaseOrderStatus::Purchased;
        }

        $lines = $raw->reject(fn (OrderProductStatus $s): bool => in_array($s, [
            OrderProductStatus::PickedReceived,
            OrderProductStatus::PickedStock,
        ], true))->values();

        if ($lines->isEmpty()) {
            return ReleaseOrderStatus::Delivered;
        }

        $eq = static fn (OrderProductStatus $v) => static fn (?OrderProductStatus $s): bool => $s === $v;

        return match (true) {
            $lines->every($eq(OrderProductStatus::Delivered)) => ReleaseOrderStatus::Delivered,
            $lines->contains($eq(OrderProductStatus::Delivered)) => ReleaseOrderStatus::PartiallyDelivered,
            $lines->every($eq(OrderProductStatus::Confirmed)) => ReleaseOrderStatus::Confirmed,
            $lines->contains($eq(OrderProductStatus::Confirmed)) => ReleaseOrderStatus::PartiallyConfirmed,
            default => ReleaseOrderStatus::Purchased,
        };
    }

    /**
     * Na bevestigen modal “alle geleverd” (afroep): optioneel laatste regel op Geleverd, daarna elke Release-regel die nog niet gepickt is → Gepickt (ingekocht).
     */
    public function applyReleaseDeliveredModalConfirm(?OrderProduct $pendingLineToMarkDelivered): void
    {
        OrderProduct::beginParentDerivationSuppression();
        try {
            if ($pendingLineToMarkDelivered !== null) {
                $pendingLineToMarkDelivered->setStatus(OrderProductStatus::Delivered);
                $pendingLineToMarkDelivered->save();
            }

            foreach ($this->orderProducts()->get() as $orderProduct) {
                if ($orderProduct->getFulfillmentType() !== FulfillmentType::Release) {
                    continue;
                }

                if (in_array($orderProduct->getStatus(), [
                    OrderProductStatus::PickedReceived,
                    OrderProductStatus::PickedStock,
                ], true)) {
                    continue;
                }

                $orderProduct->setStatus(OrderProductStatus::PickedReceived);
                $orderProduct->save();
            }
        } finally {
            OrderProduct::endParentDerivationSuppression();
        }

        $this->refresh();
        $this->unsetRelation('orderProducts');
        $this->applyDerivedStatusFromOrderProducts(fn (OrderProduct $p) => $p->getStatus());
    }

    /** @param  callable(OrderProduct): OrderProductStatus|null  $resolveLineStatus */
    public function applyDerivedStatusFromOrderProducts(callable $resolveLineStatus): void
    {
        if ($this->getStatus() === ReleaseOrderStatus::Cancelled) {
            return;
        }

        $lines = $this->orderProducts()->get(['id', 'status']);
        if ($lines->isEmpty()) {
            return;
        }

        $target = self::deriveReleaseOrderStatusFromOrderProductLineStatuses($lines->map($resolveLineStatus));
        if ($this->getStatus() !== $target) {
            $this->setStatus($target)->save();
        }
    }

    /**
     * Push header status to linked order lines (skipped for partial/cancelled header states).
     */
    public function applyStatusToOrderProducts(): void
    {
        $header = $this->getStatus();
        if ($header === null) {
            return;
        }

        $lineTarget = $header->toOrderProductStatus();
        if ($lineTarget === null) {
            return;
        }

        OrderProduct::beginParentDerivationSuppression();
        try {
            foreach ($this->orderProducts()->cursor() as $orderProduct) {
                if (in_array($orderProduct->getStatus(), [
                    OrderProductStatus::PickedReceived,
                    OrderProductStatus::PickedStock,
                ], true)) {
                    continue;
                }

                if ($orderProduct->getStatus() === $lineTarget) {
                    continue;
                }

                $orderProduct->setStatus($lineTarget);
                $orderProduct->save();
            }
        } finally {
            OrderProduct::endParentDerivationSuppression();
        }
    }

    public function getIsCancelled(): ?bool
    {
        return $this->is_cancelled;
    }

    public function setIsCancelled(?bool $isCancelled): self
    {
        $this->is_cancelled = $isCancelled;
        return $this;
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    public function getMainId(): ?int
    {
        return $this->main_id;
    }

    public function setMainId(?int $mainId): void
    {
        $this->main_id = $mainId;
    }

    public function getQuoteId(): ?int
    {
        return $this->quote_id;
    }

    public function setQuoteId(?int $quoteId): void
    {
        $this->quote_id = $quoteId;
    }

    public function getSentAt(): ?Carbon
    {
        return $this->sent_at;
    }

    public function setSentAt(?Carbon $sentAt): void
    {
        $this->sent_at = $sentAt;
    }

    /**
     * Dealerlocatie-CC is uitgefaseerd.
     *
     * @return array{display_name: string, email: string}|null
     */
    public function getDealerLocationContact(): ?array
    {
        return null;
    }

    public function getDocumentViewData(): array
    {
        $this->loadMissing(['orderProducts', 'dealer', 'main']);
        $products = $this->orderProducts()->with(['product'])->get();

        $specsFromQuote = [];
        $quote = $this->main?->getNewestApprovedQuote();
        if ($quote !== null) {
            foreach ($quote->orderProducts as $op) {
                $spec = $op->getAttributeSummaryBasic();
                if ($spec === null || $spec === '') {
                    $summary = $op->getAttributeSummary();
                    $spec = is_array($summary) ? arrayToTextareaString($summary) : '';
                }
                $specsFromQuote[$op->product_id] = $spec;
            }
        }

        return [
            'order' => $this,
            'products' => $products,
            'specsFromQuote' => $specsFromQuote,
        ];
    }
}

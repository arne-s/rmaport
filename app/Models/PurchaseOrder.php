<?php

namespace App\Models;

use App\Casts\PurchaseOrderStatusCast;
use App\Actions\SendDealerExpectedDeliveryMailAction;
use App\Actions\SendDealerNewExpectedDeliveryMailAction;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Exceptions\DuplicateTransactionalActionException;
use App\Exceptions\TransactionalActionCutoffException;
use App\Exceptions\TransactionalActionExecutionException;
use App\Exceptions\TransactionalActionValidationException;
use Eloquent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Models\Concerns\HasRecordLock;
use App\Models\Order\BaseOrder;
use App\Models\Concerns\FormatsDeliveryAddressLine;
use App\Models\Order\Main;
use App\Observers\PurchaseOrderObserver;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * App\Models\PurchaseOrder
 *
 * @property int $id
 * @property PurchaseOrderType $type
 * @property string $reference_number
 * @property int|null $order_id
 * @property int $supplier_id
 * @property int|null $author_id
 * @property PurchaseOrderStatus|null $status
 * @property bool|null $is_cancelled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Collection<int, PurchaseOrderConfirmation> $confirmations
 * @property-read int|null $confirmations_count
 * @property-read string $admin_margin_summary
 * @property-read mixed $days_till_confirmation
 * @property-read mixed $is_late
 * @property-read mixed $latest_confirmation
 * @property-read mixed $latest_confirmed_at
 * @property-read mixed $latest_expected_delivery_date
 * @property-read mixed $price_totals
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PurchaseOrderInvoice> $purchaseOrderInvoices
 * @property-read BaseOrder|null $order
 * @property-read Collection<int, OrderProduct> $orderProducts
 * @property-read int|null $order_products_count
 * @property-read Supplier $supplier
 * @property-read User|null $author
 * @method static Builder|PurchaseOrder newModelQuery()
 * @method static Builder|PurchaseOrder newQuery()
 * @method static Builder|PurchaseOrder query()
 * @method static Builder|PurchaseOrder whereCreatedAt($value)
 * @method static Builder|PurchaseOrder whereId($value)
 * @method static Builder|PurchaseOrder whereIsCancelled($value)
 * @method static Builder|PurchaseOrder whereOrderId($value)
 * @method static Builder|PurchaseOrder whereReferenceNumber($value)
 * @method static Builder|PurchaseOrder whereStatus($value)
 * @method static Builder|PurchaseOrder whereType($value)
 * @method static Builder|PurchaseOrder whereSupplierId($value)
 * @method static Builder|PurchaseOrder whereUpdatedAt($value)
 * @property int|null $main_id
 * @property int|null $quote_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property-read mixed $delivered_at
 * @property-read Collection<int, \App\Models\PurchaseOrderStatusChange> $statusChanges
 * @property-read int|null $status_changes_count
 * @property-read Collection<int, \App\Models\OrderProduct> $stockOrderProducts
 * @property-read int|null $stock_order_products_count
 * @method static Builder<static>|PurchaseOrder whereMainId($value)
 * @method static Builder<static>|PurchaseOrder whereQuoteId($value)
 * @method static Builder<static>|PurchaseOrder whereSentAt($value)
 * @mixin Eloquent
 */
#[ObservedBy([PurchaseOrderObserver::class])]
class PurchaseOrder extends Model implements HasMedia
{
    use FormatsDeliveryAddressLine;
    use HasRecordLock;
    use InteractsWithMedia;

    const PURCHASE_ORDER_LATE_AFTER_BUSINESS_DAYS = 4; // Purchase order is late after 4 business days

    protected $table = 'purchase_orders';

    protected $attributes = [
        'status' => 'initial',
    ];

    protected $fillable = [
        'type',
        'reference_number',
        'order_id',
        'main_id',
        'quote_id',
        'supplier_id',
        'author_id',
        'status',
        'sent_at',
        'is_cancelled',
        'additional',
    ];

    protected function casts(): array
    {
        return [
            'type' => PurchaseOrderType::class,
            'status' => PurchaseOrderStatusCast::class,
            'sent_at' => 'datetime',
            'additional' => 'array',
        ];
    }

    /**
     * Inkooporders die gekozen mogen worden in UI (geen concept/initial, niet geannuleerd).
     *
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeLinkable(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->where('is_cancelled', false)
                    ->orWhereNull('is_cancelled');
            })
            ->whereIn('status', array_keys(PurchaseOrderStatus::visibleStatuses()));
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeExcludingInitialStatus(Builder $query): Builder
    {
        return $query->where('purchase_orders.status', '!=', PurchaseOrderStatus::Initial->value);
    }

    public function isLinkable(): bool
    {
        if ($this->is_cancelled === true) {
            return false;
        }

        $status = $this->getStatus();

        return $status !== null
            && $status !== PurchaseOrderStatus::Initial
            && $status !== PurchaseOrderStatus::Cancelled;
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

    public function order()
    {
        return $this->belongsTo(BaseOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
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

    public function orderProducts()
    {
        return $this->hasMany(OrderProduct::class)->orderBy('sort');
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
     * When true, syncing PO header → lines (applyStatusToOrderProducts) should not run: every line is already gepickt.
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

    public function confirmations()
    {
        return $this->hasMany(PurchaseOrderConfirmation::class);
    }

    /**
     * Eager-load friendly latest confirmation (one row per PO). Use on large combined lists instead of loading all confirmations.
     */
    public function listLatestConfirmation(): HasOne
    {
        return $this->hasOne(PurchaseOrderConfirmation::class)->latestOfMany('created_at');
    }

    public function main()
    {
        return $this->belongsTo(Main::class, 'main_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(PurchaseOrderStatusChange::class);
    }

    public function stockOrderProducts(): HasMany
    {
        return $this->hasMany(OrderProduct::class, 'purchase_order_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
    }

    public function getDaysTillConfirmationAttribute()
    {
        return abs((int)($this->latestConfirmedAt ?? now())->diffInDays($this->created_at));
    }

    public function getIsLateAttribute()
    {
        return empty($this->latestConfirmedAt)
            && $this->status !== PurchaseOrderStatus::Delivered
            && now()
                ->diffInDaysFiltered(
                    fn(Carbon $date) => !$date->isToday() && $date->isWeekday(),
                    $this->created_at,
                    true
                ) >= self::PURCHASE_ORDER_LATE_AFTER_BUSINESS_DAYS;
    }

    public function getLatestConfirmationAttribute()
    {
        if ($this->relationLoaded('listLatestConfirmation')) {
            return $this->getRelation('listLatestConfirmation');
        }

        return $this->confirmations()->latest('created_at')->first();
    }

    public function getLatestConfirmedAtAttribute()
    {
        return $this->getLatestConfirmationAttribute()?->created_at;
    }

    public function getLatestExpectedDeliveryDateAttribute()
    {
        return $this->confirmations()
            ->whereNotNull('expected_delivery_date')
            ->latest('created_at')
            ->value('expected_delivery_date');
    }

    /**
     * Calculate total cost price and company price for this purchase order.
     * When linked to an order (StockOrder), uses that order's totals; otherwise sums from orderProducts.
     *
     * @return array{companyPurchasePrice: float, companySalesPrice: float}
     */
    public function calculatePriceTotals(): array
    {
        $totals = [
            'companyPurchasePrice' => 0.0,
            'companySalesPrice' => 0.0,
        ];

        $orderProducts = $this->orderProducts()->get();

        foreach ($orderProducts as $orderProduct) {
            $totals['companyPurchasePrice'] += $orderProduct->getCompanyPurchasePriceTotal();
            $totals['companySalesPrice'] += $orderProduct->getCompanySalesPriceTotal();
        }

        return $totals;
    }

    public function getPriceTotalsAttribute()
    {
        return $this->calculatePriceTotals();
    }

    public function getAdminMarginSummaryAttribute(): string
    {
        $companyPurchasePrice = $this->priceTotals['companyPurchasePrice'] ?? 0;
        $companySalesPrice = $this->priceTotals['companySalesPrice'] ?? 0;

        $margin = $companySalesPrice - $companyPurchasePrice;
        $add = '';
        if ($companyPurchasePrice > 0) {
            $percentage = ($margin / $companyPurchasePrice) * 100;
            $add = ' (' . round($percentage, 1) . '%)';
        }

        return '€' . number_format((float)$margin, 2, ',', '.') . $add;
    }

    public function getDeliveredAtAttribute()
    {
        return $this->statusChanges()
            ->where('to_status', PurchaseOrderStatus::Delivered)
            ->latest()
            ->value('created_at') ?? null;
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getType(): ?PurchaseOrderType
    {
        return $this->type;
    }

    public function setType(?PurchaseOrderType $type): self
    {
        $this->type = $type;
        return $this;
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

    public function getSupplierId(): int
    {
        return $this->supplier_id;
    }

    public function setSupplierId(int $supplierId): self
    {
        $this->supplier_id = $supplierId;
        return $this;
    }

    public function getStatus(): ?PurchaseOrderStatus
    {
        return $this->status;
    }

    public function setStatus(?PurchaseOrderStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Derive PO status from line statuses (delivered / confirmed / purchased, including partial mixes).
     *
     * Regels met alleen pick-statussen (na levering) tellen niet mee voor de mix; is daardoor niets over,
     * dan is de fase “al geleverd” → {@see PurchaseOrderStatus::Delivered}.
     *
     * @param \Illuminate\Support\Collection<int, OrderProductStatus|null> $lines
     */
    public static function derivePurchaseOrderStatusFromOrderProductLineStatuses(\Illuminate\Support\Collection $lines): PurchaseOrderStatus
    {
        $raw = $lines->filter(fn (?OrderProductStatus $s): bool => $s !== null)->values();
        if ($raw->isEmpty()) {
            return PurchaseOrderStatus::Purchased;
        }

        $lines = $raw->reject(fn (OrderProductStatus $s): bool => in_array($s, [
            OrderProductStatus::PickedReceived,
            OrderProductStatus::PickedStock,
        ], true))->values();

        if ($lines->isEmpty()) {
            return PurchaseOrderStatus::Delivered;
        }

        $eq = static fn (OrderProductStatus $v) => static fn (?OrderProductStatus $s): bool => $s === $v;

        return match (true) {
            $lines->every($eq(OrderProductStatus::Delivered)) => PurchaseOrderStatus::Delivered,
            $lines->contains($eq(OrderProductStatus::Delivered)) => PurchaseOrderStatus::PartiallyDelivered,
            $lines->every($eq(OrderProductStatus::Confirmed)) => PurchaseOrderStatus::Confirmed,
            $lines->contains($eq(OrderProductStatus::Confirmed)) => PurchaseOrderStatus::PartiallyConfirmed,
            default => PurchaseOrderStatus::Purchased,
        };
    }

    /**
     * Na bevestigen MTO-modal “alle geleverd”: optioneel laatste regel op Geleverd, daarna elke MTO-regel die nog niet gepickt is → Gepickt (ingekocht).
     */
    public function applyMtoDeliveredModalConfirm(?OrderProduct $pendingLineToMarkDelivered): void
    {
        OrderProduct::beginParentDerivationSuppression();
        try {
            if ($pendingLineToMarkDelivered !== null) {
                $pendingLineToMarkDelivered->setStatus(OrderProductStatus::Delivered);
                $pendingLineToMarkDelivered->save();
            }

            foreach ($this->orderProducts()->get() as $orderProduct) {
                if ($orderProduct->getFulfillmentType() !== FulfillmentType::MakeToOrder) {
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

    /**
     * @param callable $resolveLineStatus
     */
    public function applyDerivedStatusFromOrderProducts(callable $resolveLineStatus): void
    {
        if ($this->getStatus() === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $lines = $this->orderProducts()->get(['id', 'status']);
        if ($lines->isEmpty()) {
            return;
        }

        $target = self::derivePurchaseOrderStatusFromOrderProductLineStatuses($lines->map($resolveLineStatus));
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
                    OrderProductStatus::PickedStock
                ])) {
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

    public function purchaseOrderInvoices(): MorphMany
    {
        return $this->morphMany(PurchaseOrderInvoice::class, 'orderable');
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

    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }

    public function getMainId(): ?int
    {
        return $this->main_id;
    }

    public function setMainId(?int $main_id): void
    {
        $this->main_id = $main_id;
    }

    public function getQuoteId(): ?int
    {
        return $this->quote_id;
    }

    public function setQuoteId(?int $quote_id): void
    {
        $this->quote_id = $quote_id;
    }

    public function getSentAt(): ?\Illuminate\Support\Carbon
    {
        return $this->sent_at;
    }

    public function setSentAt(?\Illuminate\Support\Carbon $sent_at): void
    {
        $this->sent_at = $sent_at;
    }

    public function getDeliveredAt(): mixed
    {
        return $this->delivered_at;
    }

    public function setDeliveredAt(mixed $delivered_at): void
    {
        $this->delivered_at = $delivered_at;
    }

    public function syncDeliveryWeekFromConfirmation(Carbon $expectedDeliveryDate): self
    {
        $additional = $this->getAdditional() ?? [];
        $additional['delivery_week'] = $expectedDeliveryDate->translatedFormat('\W\e\e\k W, Y');
        $this->setAdditional($additional);

        return $this;
    }

    /**
     * @throws DuplicateTransactionalActionException
     * @throws TransactionalActionCutoffException
     * @throws TransactionalActionExecutionException
     * @throws TransactionalActionValidationException
     */
    public function sendExpectedDeliveryDateNotification(
        string $expectedDeliveryDate,
        bool $isFirstConfirmation,
        bool $isNewConfirmation,
    ): bool {
        if ($this->getType() !== PurchaseOrderType::Order) {
            return false;
        }

        $order = $this->main ?? $this->order;
        if ($order === null) {
            return false;
        }

        $emails = OrderCustomerMailRecipients::resolveEmails($order, ['dealer']);
        $email = $emails[0] ?? null;
        if ($email === null || $email === '') {
            return false;
        }

        if ($isFirstConfirmation) {
            (new SendDealerExpectedDeliveryMailAction($this, $expectedDeliveryDate, $email))
                ->execute();
        } elseif ($isNewConfirmation) {
            (new SendDealerNewExpectedDeliveryMailAction($this, $expectedDeliveryDate, $email))
                ->execute();
        } else {
            return false;
        }

        return true;
    }
}

<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\HasRecordLock;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\PackingSlip;
use App\Observers\OrderProductObserver;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Exception;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;


/**
 * App\Models\OrderProduct
 *
 * @property int $id
 * @property string $value
 * @property float $qty
 * @property int $sort
 * @property array|null $attribute_summary
 * @property array|null $supplier_attributes
 * @property int|null $product_id
 * @property int $order_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $supplier_id
 * @property array|null $delivery_address
 * @property OrderProductStatus|null $status
 * @property-read BaseOrder $order
 * @property-read Product|null $product
 * @method static Builder|OrderProduct newModelQuery()
 * @method static Builder|OrderProduct newQuery()
 * @method static Builder|OrderProduct query()
 * @method static Builder|OrderProduct whereAttributeSummary($value)
 * @method static Builder|OrderProduct whereBasePrice($value)
 * @method static Builder|OrderProduct whereCreatedAt($value)
 * @method static Builder|OrderProduct whereDeliveryAddress($value)
 * @method static Builder|OrderProduct whereFulfillmentType($value)
 * @method static Builder|OrderProduct whereId($value)
 * @method static Builder|OrderProduct whereItemPrice($value)
 * @method static Builder|OrderProduct whereMargin($value)
 * @method static Builder|OrderProduct whereOrderId($value)
 * @method static Builder|OrderProduct whereProductId($value)
 * @method static Builder|OrderProduct wherePurchasePrice($value)
 * @method static Builder|OrderProduct whereQty($value)
 * @method static Builder|OrderProduct whereSort($value)
 * @method static Builder|OrderProduct whereStatus($value)
 * @method static Builder|OrderProduct whereSupplierId($value)
 * @method static Builder|OrderProduct whereTotalPrice($value)
 * @method static Builder|OrderProduct whereUpdatedAt($value)
 * @method static Builder|OrderProduct whereValue($value)
 * @method static Builder|OrderProduct whereAdditionalPrice($value)
 * @property-read float $item_price_ex_vat
 * @property ProductType|null $type
 * @property bool $is_configurable
 * @property-read Supplier|null $supplier
 * @method static Builder|OrderProduct whereIsConfigurable($value)
 * @method static Builder|OrderProduct whereItemPriceExMargin($value)
 * @property float $vat
 * @method static Builder|OrderProduct whereVat($value)
 * @method static Builder|OrderProduct whereTotalPriceIncVat($value)
 * @method static Builder|OrderProduct whereAdditionalPriceCompany($value)
 * @method static Builder|OrderProduct whereBasePriceCompany($value)
 * @property array|null $attribute_summary_company
 * @method static Builder|OrderProduct whereAttributeSummaryCompany($value)
 * @property string|null $doc
 * @property Carbon|null $delivered_at
 * @property Carbon|null $purchased_at
 * @property int|null $purchase_order_id
 * @property-read PurchaseOrder|null $purchaseOrder
 * @method static Builder|OrderProduct whereDoc($value)
 * @method static Builder|OrderProduct wherePurchaseOrderId($value)
 * @property string|null $attribute_summary_basic
 * @method static Builder|OrderProduct whereAttributeSummaryBasic($value)
 * @method static Builder|OrderProduct whereReference($value)
 * @property string|null $reference
 * @property-read string $sp_margin_summary
 * @method static Builder|OrderProduct whereMarginAmountCalc($value)
 * @method static Builder|OrderProduct whereMarginCalc($value)
 * @method static Builder|OrderProduct whereIsLinked($value)
 * @property bool $has_credit
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @method static Builder|OrderProduct whereCreditedAmount($value)
 * @method static Builder|OrderProduct whereHasCredit($value)
 * @method static Builder|OrderProduct whereSummaryItems($value)
 * @property string $company_purchase_price_base Inkoop | Basisprijs
 * @property string $company_purchase_price_additional Inkoop | Meerprijs
 * @property string $company_purchase_price_subtotal Inkoop | Subtotaal
 * @property string $company_purchase_price_total Inkoop | Totaal
 * @property string $company_sales_price_base Verkoop | Basisprijs
 * @property string $company_sales_price_additional Verkoop | Meerprijs
 * @property float $company_sales_price_discount_percentage Verkoop | Kortingspercentage
 * @property string $company_sales_price_discount Verkoop | Korting
 * @property string $company_sales_price_subtotal Verkoop | Subtotaal
 * @property string $company_sales_price_total Verkoop | Totaal
 * @property string $company_sales_price_credited Verkoop | Gecrediteerd
 * @property-read mixed $last_delivered
 * @property-read OrderProductStatusChange|null $last_picked
 * @property-read Collection<int, OrderProductStatusChange> $statusChanges
 * @property-read OrderProductStatusChange|null latestPickedStatusChange
 * @property-read int|null $status_changes_count
 * @method static Builder<static>|OrderProduct whereCompanyPurchasePriceAdditional($value)
 * @method static Builder<static>|OrderProduct whereCompanyPurchasePriceBase($value)
 * @method static Builder<static>|OrderProduct whereCompanyPurchasePriceSubtotal($value)
 * @method static Builder<static>|OrderProduct whereCompanyPurchasePriceTotal($value)
 * @method static Builder<static>|OrderProduct whereCompanySalesPriceAdditional($value)
 * @method static Builder<static>|OrderProduct whereCompanySalesPriceBase($value)
 * @method static Builder<static>|OrderProduct whereCompanySalesPriceCredited($value)
 * @method static Builder<static>|OrderProduct whereCompanySalesPriceSubtotal($value)
 * @method static Builder<static>|OrderProduct whereCompanySalesPriceTotal($value)
 * @mixin Eloquent
 */
#[ObservedBy([OrderProductObserver::class])]
class OrderProduct extends Model implements HasMedia
{
    /**
     * When > 0, line status changes do not re-derive parent PO/RO/Main (avoids feedback loops during bulk header→lines sync).
     */
    private static int $parentDerivationSuppressionDepth = 0;

    use HasRecordLock;
    use InteractsWithMedia;

    protected $fillable = [
        'value',
        'company_purchase_price_base',
        'company_purchase_price_additional',
        'company_purchase_price_subtotal',
        'company_purchase_price_total',
        'company_sales_price_base',
        'company_sales_price_additional',
        'company_sales_price_discount_percentage',
        'company_sales_price_discount',
        'company_sales_price_credited',
        'product_id',
        'purchase_order_id',
        'release_order_id',
        'supplier_id',
        'type',
        'attribute_summary',
        'attribute_summary_company',
        'attribute_summary_basic',
        'vat',
        'is_configurable',
        'has_credit',
        'order_id',
        'packing_slip_id',
        'qty',
        'sort',
        'delivery_address',
        'fulfillment_type',
        'status',
        'delivered_at',
        'purchased_at',
        'additional',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderProduct $orderProduct): void {
            if ((int) ($orderProduct->sort ?? 0) > 0) {
                return;
            }

            $query = static::query();

            if ($orderProduct->order_id !== null) {
                $query->where('order_id', $orderProduct->order_id);
            } else {
                $query->whereNull('order_id');
            }

            $max = $query->max('sort');
            $orderProduct->sort = (int) $max + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'attribute_summary' => 'array',
            'attribute_summary_company' => 'array',
            'supplier_attributes' => 'array',
            'is_configurable' => 'boolean',
            'has_credit' => 'boolean',
            'delivery_address' => 'array',
            'company_sales_price_discount_percentage' => 'decimal:2',
            'fulfillment_type' => FulfillmentType::class,
            'type' => ProductType::class,
            'status' => OrderProductStatus::class,
            'delivered_at' => 'datetime',
            'purchased_at' => 'datetime',
            'additional' => 'array',
        ];
    }

    public function getPurchasedAt(): ?Carbon
    {
        return $this->purchased_at;
    }

    public function setPurchasedAt(?Carbon $purchasedAt): self
    {
        $this->purchased_at = $purchasedAt;

        return $this;
    }

    public function hasBeenInPurchaseProcess(): bool
    {
        return $this->purchased_at !== null;
    }

    public function getAdditionalOrderRev(): ?int
    {
        $rev = data_get($this->additional, 'order_rev');

        return is_numeric($rev) ? (int) $rev : null;
    }

    public function getAdditionalUpdatedBy(): ?string
    {
        $name = data_get($this->additional, 'updated_by');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function getAdditionalSourceOrderId(): ?int
    {
        $id = data_get($this->additional, 'order_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public static function rules(): array
    {
        return [
            'value' => 'required',
            'item_price' => 'required|numeric',
            'sort' => 'required|integer',
            'product_id' => 'nullable|exists:products,id',
            'order_id' => 'nullable|exists:orders,id',
        ];
    }

    public function duplicate(): OrderProduct
    {
        DB::beginTransaction();
        try {
            $newOrderProduct = $this->replicate();
            $newOrderProduct->packing_slip_id = null;
            $newOrderProduct->save();

            DB::commit();
            return $newOrderProduct;

        } catch (Throwable $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }

    }

    public function getSpMarginSummaryAttribute(): string
    {
        $salesTotal = $this->company_sales_price_total ?? 0;
        $purchaseTotal = $this->company_purchase_price_total ?? 0;
        $marginPrice = $salesTotal - $purchaseTotal;

        if ($purchaseTotal == 0) {
            $marginPercentage = 0;
        } else {
            $marginPercentage = ($marginPrice / $purchaseTotal) * 100;
        }

        return '€' . number_format((float)$marginPrice, 1, ',', '.')
            . ' (' . number_format($marginPercentage, 1, ',', '.') . '%)';
    }

    public static function translate($str)
    {
        return [
            'width' => 'Lengte',
            'color' => 'Kleur',
        ][$str] ?? $str;
    }

    public function getImage($conversion): ?string
    {
        return $this->media->first()?->getUrl($conversion) ?? $this->product?->getImage($conversion);
    }

    public function attributeSummary(): ?array
    {
        if (!empty($this->getAttributeSummaryBasic()) && $this->order->getIsAdminGenerated()) {
            return [$this->getAttributeSummaryBasic()];
        }

        if (in_array($this->order->getType(), ['invoice', 'deposit_invoice'])) {
            return $this->getAttributeSummaryCompany();
        }
        return $this->getAttributeSummary();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function packingSlip(): BelongsTo
    {
        return $this->belongsTo(PackingSlip::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)
            ->where('status', '!=', 'initial');
    }

    public function releaseOrder(): BelongsTo
    {
        return $this->belongsTo(ReleaseOrder::class)
            ->where('status', '!=', 'initial');
    }

    public function initialPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class)
            ->where('status', '!=', 'initial');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BaseOrder::class, 'order_id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderProductStatusChange::class);
    }

    public function latestPickedStatusChange(): HasOne
    {
        return $this->hasOne(OrderProductStatusChange::class, 'order_product_id')
            ->whereIn('to_status', [OrderProductStatus::PickedStock->value, OrderProductStatus::PickedReceived->value])
            ->latestOfMany('id');
    }

    public function getItemPriceExVatAttribute(): float
    {
        return Order::exVat((float)($this->company_sales_price_total ?? 0));
    }


    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @param string $value
     * @return OrderProduct
     */
    public function setValue(string $value): OrderProduct
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @return float
     */
    public function getVat(): float
    {
        return $this->vat;
    }

    /**
     * @param float $vat
     * @return OrderProduct
     */
    public function setVat(float $vat): OrderProduct
    {
        $this->vat = $vat;
        return $this;
    }


    /**
     * @return float
     */
    public function getQty(): float
    {
        return $this->qty;
    }

    /**
     * @param float $qty
     * @return OrderProduct
     */
    public function setQty(float $qty): OrderProduct
    {
        $this->qty = $qty;
        return $this;
    }

    /**
     * @return int
     */
    public function getSort(): int
    {
        return $this->sort;
    }

    /**
     * @param int $sort
     * @return OrderProduct
     */
    public function setSort(int $sort): OrderProduct
    {
        $this->sort = $sort;
        return $this;
    }

    /**
     * @return array|string|null
     */
    public function getAttributeSummary(): null|string|array
    {
        return $this->attribute_summary;
    }

    /**
     * @param array|null $attribute_summary
     * @return OrderProduct
     */
    public function setAttributeSummary(?array $attribute_summary): OrderProduct
    {
        $this->attribute_summary = $attribute_summary;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getAttributeSummaryCompany(): ?array
    {
        return $this->attribute_summary_company;
    }

    /**
     * @param array|null $attribute_summary_company
     * @return OrderProduct
     */
    public function setAttributeSummaryCompany(?array $attribute_summary_company): OrderProduct
    {
        $this->attribute_summary_company = $attribute_summary_company;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getProductId(): ?int
    {
        return $this->product_id;
    }

    /**
     * @param int|null $product_id
     * @return OrderProduct
     */
    public function setProductId(?int $product_id): OrderProduct
    {
        $this->product_id = $product_id;
        return $this;
    }

    /**
     * @return ?int
     */
    public function getOrderId(): ?int
    {
        return $this->order_id;
    }

    /**
     * @param ?int $order_id
     * @return OrderProduct
     */
    public function setOrderId(?int $order_id): OrderProduct
    {
        $this->order_id = $order_id;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getPurchaseOrderId(): ?int
    {
        return $this->purchase_order_id;
    }

    /**
     * @param int|null $purchase_order_id
     * @return OrderProduct $purchase_order_id
     */
    public function setPurchaseOrderId(?int $purchase_order_id): OrderProduct
    {
        $this->purchase_order_id = $purchase_order_id;
        return $this;
    }

    public function getReleaseOrderId(): ?int
    {
        return $this->release_order_id;
    }

    public function setReleaseOrderId(?int $release_order_id): OrderProduct
    {
        $this->release_order_id = $release_order_id;
        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    /**
     * @return Carbon|null
     */
    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }

    /**
     * @param BaseOrder $order
     * @return OrderProduct
     */
    public function setOrder(BaseOrder $order): OrderProduct
    {
        $this->order = $order;
        return $this;
    }

    /**
     * @return Product|null
     */
    public function getProduct(): ?Product
    {
        return $this->product;
    }

    /**
     * @param Product|null $product
     * @return OrderProduct
     */
    public function setProduct(?Product $product): OrderProduct
    {
        $this->product = $product;
        return $this;
    }

    public function getPriceIncludedProducts(): float
    {
        if ($this->order?->getType() === 'credit_invoice') {
            return $this->getCompanySalesPriceCredited();
        }

        return (float) $this->getCompanySalesPriceTotal();
    }

    public function getCompanyPriceIncludedProducts(): float
    {
        return (float) $this->getCompanySalesPriceTotal();
    }

    /**
     * @return int|null
     */
    public function getSupplierId(): ?int
    {
        return $this->supplier_id;
    }

    /**
     * @param int|null $supplier_id
     * @return OrderProduct
     */
    public function setSupplierId(?int $supplier_id): OrderProduct
    {
        $this->supplier_id = $supplier_id;
        return $this;
    }

    /**
     * @return bool
     */
    public function getIsConfigurable(): bool
    {
        return (bool)$this->is_configurable;
    }

    /**
     * @param bool $is_configurable
     * @return OrderProduct
     */
    public function setIsConfigurable(bool $is_configurable): OrderProduct
    {
        $this->is_configurable = $is_configurable;
        return $this;
    }

    public function getType(): ?ProductType
    {
        $type = $this->type;

        return $type instanceof ProductType ? $type : ProductType::tryFrom((string) $type);
    }

    public function setType(ProductType|string|null $type): OrderProduct
    {
        if ($type === null || $type === '') {
            $this->type = null;
        } elseif ($type instanceof ProductType) {
            $this->type = $type;
        } else {
            $this->type = ProductType::tryFrom((string) $type);
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function getHasCredit(): bool
    {
        return $this->has_credit;
    }

    /**
     * @param bool $has_credit
     * @return OrderProduct
     */
    public function setHasCredit(bool $has_credit): OrderProduct
    {
        $this->has_credit = $has_credit;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getDeliveryAddress(): ?array
    {
        return $this->delivery_address;
    }

    /**
     * @param array|null $delivery_address
     * @return OrderProduct
     */
    public function setDeliveryAddress(?array $delivery_address): OrderProduct
    {
        $this->delivery_address = $delivery_address;
        return $this;
    }

    /**
     * @return FulfillmentType|null
     */
    public function getFulfillmentType(): ?FulfillmentType
    {
        return $this->fulfillment_type;
    }

    /**
     * @param FulfillmentType|null $fulfillment_type
     * @return OrderProduct
     */
    public function setFulfillmentType(?FulfillmentType $fulfillment_type): OrderProduct
    {
        $this->fulfillment_type = $fulfillment_type;
        return $this;
    }

    /**
     * @return OrderProductStatus|null
     */
    public function getStatus(): ?OrderProductStatus
    {
        return $this->status;
    }

    /**
     * @param OrderProductStatus|null $status
     * @return OrderProduct
     */
    public function setStatus(?OrderProductStatus $status): OrderProduct
    {
        $this->status = $status;
        return $this;
    }


    public static function beginParentDerivationSuppression(): void
    {
        self::$parentDerivationSuppressionDepth++;
    }

    public static function endParentDerivationSuppression(): void
    {
        self::$parentDerivationSuppressionDepth = max(0, self::$parentDerivationSuppressionDepth - 1);
    }

    public function statusChange(StatusChange $change): void
    {
        if ((int) $change->getOrderProductId() !== (int) $this->getKey()) {
            return;
        }

        $thisId = (int) $this->getKey();
        $toEnum = OrderProductStatus::tryFrom((string) $change->getToStatus());

        $resolveLineStatus = function (OrderProduct $op) use ($thisId, $toEnum): ?OrderProductStatus {
            if ((int) $op->getKey() === $thisId && $toEnum !== null) {
                return $toEnum;
            }

            return $op->getStatus();
        };

        if (self::$parentDerivationSuppressionDepth === 0) {
            $this->purchaseOrder?->applyDerivedStatusFromOrderProducts($resolveLineStatus);
            $this->releaseOrder?->applyDerivedStatusFromOrderProducts($resolveLineStatus);
        }

        $order = $this->order;
        $main = $order instanceof Main ? $order : $order?->getMain();
        $main?->applyDerivedOrderStatusFromOrderProducts($resolveLineStatus);
    }


    public function getAttributeSummaryBasic(): ?string
    {
        return $this->attribute_summary_basic;
    }

    public function setAttributeSummaryBasic(?string $attribute_summary_basic): OrderProduct
    {
        $this->attribute_summary_basic = $attribute_summary_basic;
        return $this;
    }

    public function updateAttributeSummaryBasic(): void
    {
        $summary = $this->getAttributeSummary();
        if (is_array($summary)) {
            $summary = $this->removePrices($summary);
            $summary = arrayToTextareaString($summary);
            $this->setAttributeSummaryBasic($summary);
        }
    }


    /**
     * Set fulfillment type based on the is_stock_enabled value on Product.
     * @return OrderProduct
     */
    public function setFulfillmentTypeBasedOnProduct(): OrderProduct
    {
        /** @var Product */
        $product = $this->product;
        if (!empty($product)) {
            $this->fulfillment_type = $product->getIsStockEnabled()
                ? FulfillmentType::MakeToStock
                : FulfillmentType::MakeToOrder;
        }
        return $this;
    }

    /**
     * @return float|null
     */
    public function getCompanyPurchasePriceBase(): ?float
    {
        return $this->company_purchase_price_base;
    }

    /**
     * @param float|null $company_purchase_price_base
     * @return OrderProduct
     */
    public function setCompanyPurchasePriceBase(?float $company_purchase_price_base): OrderProduct
    {
        $this->company_purchase_price_base = $company_purchase_price_base;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getCompanyPurchasePriceAdditional(): ?float
    {
        return $this->company_purchase_price_additional;
    }

    /**
     * @param float $company_purchase_price_additional
     * @return OrderProduct
     */
    public function setCompanyPurchasePriceAdditional(float $company_purchase_price_additional): OrderProduct
    {
        $this->company_purchase_price_additional = $company_purchase_price_additional;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getCompanyPurchasePriceSubtotal(): float
    {
        return $this->company_purchase_price_subtotal;
    }

    /**
     * @return float|null
     */
    public function getCompanyPurchasePriceTotal(): float
    {
        return $this->company_purchase_price_total;
    }

    /**
     * @return string|null
     */
    public function getCompanySalesPriceBase(): float
    {
        return $this->company_sales_price_base;
    }

    /**
     * @param float $company_sales_price_base
     * @return OrderProduct
     */
    public function setCompanySalesPriceBase(float $company_sales_price_base): OrderProduct
    {
        $this->company_sales_price_base = $company_sales_price_base;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCompanySalesPriceAdditional(): float
    {
        return $this->company_sales_price_additional;
    }

    /**
     * @param string|null $company_sales_price_additional
     * @return OrderProduct
     */
    public function setCompanySalesPriceAdditional(float $company_sales_price_additional): OrderProduct
    {
        $this->company_sales_price_additional = $company_sales_price_additional;
        return $this;
    }

    public function getCompanySalesPriceDiscountPercentage(): float
    {
        return (float) $this->company_sales_price_discount_percentage;
    }

    public function setCompanySalesPriceDiscountPercentage(float $value): self
    {
        $this->company_sales_price_discount_percentage = $value;
        return $this;
    }

    public function getCompanySalesPriceDiscount(): float
    {
        return (float) $this->company_sales_price_discount;
    }

    /**
     * @return float|null
     */
    public function getCompanySalesPriceSubtotal(): float
    {
        return $this->company_sales_price_subtotal;
    }

    /**
     * @return float|null
     */
    public function getCompanySalesPriceTotal(): float
    {
        return $this->company_sales_price_total;
    }

    /**
     * @return float|null
     */
    public function getCompanySalesPriceCredited(): float
    {
        return $this->company_sales_price_credited;
    }

    /**
     * @param float $company_sales_price_credited
     * @return OrderProduct
     */
    public function setCompanySalesPriceCredited(float $company_sales_price_credited): OrderProduct
    {
        $this->company_sales_price_credited = $company_sales_price_credited;
        return $this;
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('medium-large')
            ->fit(Fit::Contain, 400, 215)
            ->nonQueued();
    }

    public function getCalculatedCompanyMarginAmountSubtotal(): float
    {
        return $this->getCompanySalesPriceSubtotal() - $this->getCompanyPurchasePriceSubtotal();
    }

    public function getCalculatedCompanyMarginAmountTotal(): float
    {
        return $this->getCompanySalesPriceTotal() - $this->getCompanyPurchasePriceTotal();
    }

    public function getCalculatedCompanyMarginPercentage(): ?float
    {
        if ($this->getCompanyPurchasePriceTotal() == 0) {
            return null;
        }
        // Margin % = profit as a percentage of purchase: (sales - purchase) / purchase * 100
        return (($this->getCompanySalesPriceTotal() / $this->getCompanyPurchasePriceTotal()) - 1) * 100;
    }
}

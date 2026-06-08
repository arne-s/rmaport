<?php

namespace App\Models\Order;

use App\Actions\SendCreditInvoiceMailAction;
use App\Actions\SendDepositInvoiceMailAction;
use App\Actions\SendInvoiceMailAction;
use App\Actions\SendInvoicePaymentNotificationMailAction;
use App\Enums\AddressType;
use App\Enums\CustomerAddressType;
use App\Enums\CustomerType;
use App\Enums\InvoiceCaption;
use App\Enums\FulfillmentType;
use App\Enums\OrderGeneralStatus;
use App\Casts\OrderStatusCast;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Enums\PaymentMethodType;
use App\Enums\ProductType;
use App\Enums\ValidityPeriod;
use App\Exceptions\OrderNotDuplicatedException;
use App\Models\Address;
use App\Models\Customer;
use App\Models\ExactPaymentCondition;
use App\Models\Setting;
use App\Support\InvoiceReminderSettings;
use App\Models\Document;
use App\Models\OrderProduct;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\OrderEvent;
use App\Models\PaymentLink;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\Concerns\HasRecordLock;
use App\Traits\Order\DecoratorTrait;
use App\Traits\Order\StatsTrait;
use Carbon\CarbonInterface;
use Exception;
use InvalidArgumentException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Throwable;


/**
 * @property int $id
 * @property OrderType|null $type
 * @property int|null $main_id
 * @property int|null $quote_id
 * @property OrderGeneralStatus|null $status
 * @property OrderStatus|null $order_status
 * @property string|null $uid
 * @property int $rev
 * @property ValidityPeriod|null $validity_period
 * @property OrderSubtype|null $subtype
 * @property int $is_test
 * @property numeric $company_purchase_price_base Inkoop | Basisprijs
 * @property numeric $company_purchase_price_discount Inkoop | Inkoopkorting
 * @property numeric $company_purchase_price_total Inkoop | Totaal
 * @property numeric $company_sales_price_base Verkoop | Basisprijs
 * @property numeric $company_sales_price_discount Verkoop | Inkoopkorting
 * @property numeric $company_sales_price_total Verkoop | Totaal
 * @property numeric|null $payment_percentage
 * @property numeric|null $payment_amount
 * @property numeric $deposit_amount
 * @property int|null $payment_link_id
 * @property string|null $reference
 * @property string|null $reference_internal
 * @property int $is_verified
 * @property int $is_force_verified
 * @property string|null $discount_comment
 * @property string|null $order_comment
 * @property int|null $customer_id
 * @property CustomerAddressType $customer_address_type
 * @property int|null $shipping_customer_id
 * @property int|null $billing_customer_id
 * @property int|null $advisor_id
 * @property int|null $author_id
 * @property PaymentTerms|null $payment_terms
 * @property PaymentMethodType|null $payment_method
 * @property int|null $delivery_advisor_id
 * @property int|null $supplier_id
 * @property int|null $order_id
 * @property int|null $deposit_invoice_id
 * @property int|null $invoice_id
 * @property int|null $credit_invoice_id
 * @property int|null $recurring_order_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $sent_at
 * @property string|null $public_download_uuid
 * @property Carbon|null $expires_at
 * @property Carbon|null $first_reminder_sent_at
 * @property Carbon|null $second_reminder_sent_at
 * @property Carbon|null $payment_verify_mail_sent_at
 * @property string|null $exact_id
 * @property Carbon|null $exact_synced_at
 * @property Carbon|null $exact_error_at
 * @property array<array-key, mixed>|null $additional
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $fitting_note
 * @property array<string, mixed>|null $delivery_note
 * @property array<int, array{code: string, current: string, new: string, custom: bool}>|null $fitting_measurements
 * @property array<int, array{description: string, date: string}>|null $checklist
 * @property Carbon|null $quote_created_at
 * @property Carbon|null $cancelled_at
 * @property int|null $is_cancelled
 * @property int $is_cancellation_credit_invoice
 * @property string|null $cancel_comment
 * @property-read User|null $advisor
 * @property-read User|null $author
 * @property-read Customer|null $billingCustomer
 * @property-read Customer|null $shippingCustomer
 * @property-read CreditInvoice|null $creditInvoice
 * @property-read Collection<int, OrderProduct> $customOrderProducts
 * @property-read int|null $custom_order_products_count
 * @property-read Customer|null $customer
 * @property-read User|null $deliveryAdvisor
 * @property-read DepositInvoice|null $depositInvoice
 * @property-read string $created_at_short
 * @property-read mixed $latest_expected_delivery_date
 * @property-read string $sp_margin_summary
 * @property-read string $type_translated
 * @property-read string $uid_formatted
 * @property-read Invoice|null $invoice
 * @property-read Collection<int, OrderProduct> $mtoOrderProducts
 * @property-read int|null $mto_order_products_count
 * @property-read Collection<int, OrderProduct> $mtsOrderProducts
 * @property-read int|null $mts_order_products_count
 * @property-read Order|null $order
 * @property-read Main|null $main
 * @property-read Collection<int, BaseOrder> $children
 * @property-read Collection<int, BaseOrder> $fittings
 * @property-read Product|null $frameProduct
 * @property-read Collection<int, OrderProduct> $orderProducts
 * @property-read int|null $order_products_count
 * @property-read int|null $order_products_without_custom_products_count
 * @property-read Collection<int, OrderStatusChange> $statusChanges
 * @property-read int|null $status_changes_count
 * @method static Builder<static>|BaseOrder newModelQuery()
 * @method static Builder<static>|BaseOrder newQuery()
 * @method static Builder<static>|BaseOrder query()
 * @method static Builder<static>|BaseOrder status(string $status)
 * @method static Builder<static>|BaseOrder type(string $type)
 * @method static Builder<static>|BaseOrder whereAdditional($value)
 * @method static Builder<static>|BaseOrder whereAdvisorId($value)
 * @method static Builder<static>|BaseOrder whereAuthorId($value)
 * @method static Builder<static>|BaseOrder whereCancelComment($value)
 * @method static Builder<static>|BaseOrder whereCancelledAt($value)
 * @method static Builder<static>|BaseOrder whereCompanyPurchasePriceBase($value)
 * @method static Builder<static>|BaseOrder whereCompanyPurchasePriceDiscount($value)
 * @method static Builder<static>|BaseOrder whereCompanyPurchasePriceTotal($value)
 * @method static Builder<static>|BaseOrder whereCompanySalesPriceBase($value)
 * @method static Builder<static>|BaseOrder whereCompanySalesPriceDiscount($value)
 * @method static Builder<static>|BaseOrder whereCompanySalesPriceTotal($value)
 * @method static Builder<static>|BaseOrder whereCreatedAt($value)
 * @method static Builder<static>|BaseOrder whereCreditInvoiceId($value)
 * @method static Builder<static>|BaseOrder whereCustomerId($value)
 * @method static Builder<static>|BaseOrder whereInvoiceCustomerId($value)
 * @method static Builder<static>|BaseOrder whereShippingCustomerId($value)
 * @method static Builder<static>|BaseOrder whereDeliveryAdvisorId($value)
 * @method static Builder<static>|BaseOrder whereDepositAmount($value)
 * @method static Builder<static>|BaseOrder whereDepositInvoiceId($value)
 * @method static Builder<static>|BaseOrder whereDiscountComment($value)
 * @method static Builder<static>|BaseOrder whereExactErrorAt($value)
 * @method static Builder<static>|BaseOrder whereExactId($value)
 * @method static Builder<static>|BaseOrder whereExactSyncedAt($value)
 * @method static Builder<static>|BaseOrder whereExpiresAt($value)
 * @method static Builder<static>|BaseOrder whereId($value)
 * @method static Builder<static>|BaseOrder whereInvoiceId($value)
 * @method static Builder<static>|BaseOrder whereIsCancellationCreditInvoice($value)
 * @method static Builder<static>|BaseOrder whereIsCancelled($value)
 * @method static Builder<static>|BaseOrder whereIsForceVerified($value)
 * @method static Builder<static>|BaseOrder whereIsTest($value)
 * @method static Builder<static>|BaseOrder whereIsVerified($value)
 * @method static Builder<static>|BaseOrder whereOrderComment($value)
 * @method static Builder<static>|BaseOrder whereOrderId($value)
 * @method static Builder<static>|BaseOrder whereOrderStatus($value)
 * @method static Builder<static>|BaseOrder wherePaymentAmount($value)
 * @method static Builder<static>|BaseOrder wherePaymentId($value)
 * @method static Builder<static>|BaseOrder wherePaymentPercentage($value)
 * @method static Builder<static>|BaseOrder whereQuoteCreatedAt($value)
 * @method static Builder<static>|BaseOrder whereReference($value)
 * @method static Builder<static>|BaseOrder whereRev($value)
 * @method static Builder<static>|BaseOrder whereDeliveryNote($value)
 * @method static Builder<static>|BaseOrder whereSentAt($value)
 * @method static Builder<static>|BaseOrder whereStatus($value)
 * @method static Builder<static>|BaseOrder whereSubtype($value)
 * @method static Builder<static>|BaseOrder whereSupplierId($value)
 * @method static Builder<static>|BaseOrder whereType($value)
 * @method static Builder<static>|BaseOrder whereUid($value)
 * @method static Builder<static>|BaseOrder whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class BaseOrder extends Model implements HasMedia
{
    use HasRecordLock;
    use InteractsWithMedia;
    use DecoratorTrait,
        StatsTrait;

    const VAT_PERCENTAGE = 21;

    protected $fillable = [
        'type',
        'main_id',
        'quote_id',
        'status',
        'order_status',
        'is_completed',
        'uid',
        'rev',
        'validity_period',
        'subtype',
        'is_test',
        'company_purchase_price_base',
        'company_purchase_price_discount',
        'company_purchase_price_total',
        'company_sales_price_base',
        'company_sales_price_discount',
        'company_sales_price_total',
        'payment_percentage',
        'payment_amount',
        'deposit_amount',
        'paid_at',
        'payment_method',
        'payment_link_id',
        'reference',
        'reference_internal',
        'is_verified',
        'is_force_verified',
        'discount_comment',
        'order_comment',
        'product_summary',
        'customer_id',
        'customer_address_type',
        'shipping_customer_id',
        'billing_customer_id',
        'advisor_id',
        'author_id',
        'payment_terms',
        'delivery_advisor_id',
        'supplier_id',
        'order_id',
        'fitting_note',
        'delivery_note',
        'fitting_measurements',
        'checklist',
        'deposit_invoice_id',
        'invoice_id',
        'credit_invoice_id',
        'recurring_order_id',
        'sent_at',
        'public_download_uuid',
        'expires_at',
        'first_reminder_sent_at',
        'second_reminder_sent_at',
        'payment_verify_mail_sent_at',
        'exact_id',
        'exact_synced_at',
        'exact_error_at',
        'additional',
        'quote_created_at',
        'cancelled_at',
        'is_cancelled',
        'is_cancellation_credit_invoice',
        'cancel_comment',
        'caption',
    ];

    protected $table = 'orders';

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderGeneralStatus::class,
            'order_status' => OrderStatusCast::class,
            'is_completed' => 'boolean',
            'subtype' => OrderSubtype::class,
            'validity_period' => ValidityPeriod::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'first_reminder_sent_at' => 'datetime',
            'second_reminder_sent_at' => 'datetime',
            'payment_verify_mail_sent_at' => 'datetime',
            'exact_synced_at' => 'datetime',
            'exact_error_at' => 'datetime',
            'additional' => 'array',
            'fitting_note' => 'array',
            'delivery_note' => 'array',
            'service_note' => 'array',
            'fitting_measurements' => 'array',
            'checklist' => 'array',
            'quote_created_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_method' => PaymentMethodType::class,
            'payment_terms' => PaymentTerms::class,
            'customer_address_type' => CustomerAddressType::class,
            'caption' => InvoiceCaption::class,
        ];
    }

    /**
     * Keys: order row id → main id. Set in registerPartSlotInvoicePaidNotification() during the updating event.
     *
     * @var array<int, int>
     */
    protected static array $pendingPartSlotInvoicePaidNotificationByOrderId = [];

    protected static function booted(): void
    {
        static::updating(function (BaseOrder $order): void {
            static::registerPartSlotInvoicePaidNotification($order);
        });

        static::updated(function (BaseOrder $order): void {
            $key = $order->getKey();
            if ($key === null) {
                return;
            }

            if (! isset(static::$pendingPartSlotInvoicePaidNotificationByOrderId[$key])) {
                return;
            }

            $mainId = static::$pendingPartSlotInvoicePaidNotificationByOrderId[$key];
            unset(static::$pendingPartSlotInvoicePaidNotificationByOrderId[$key]);

            $main = Main::query()->find($mainId);
            if ($main === null) {
                return;
            }

            app(SendInvoicePaymentNotificationMailAction::class)->execute($main);
        });

        static::saved(function (BaseOrder $order): void {
            if (! $order->wasRecentlyCreated) {
                return;
            }

            if ($order->paid_at === null) {
                return;
            }

            if ($order->getType() !== OrderType::Invoice) {
                return;
            }

            $mainId = $order->main_id;
            if ($mainId === null) {
                return;
            }

            $main = Main::query()->find($mainId);
            if ($main === null || $main->getSubtype() !== OrderSubtype::Part) {
                return;
            }

            app(SendInvoicePaymentNotificationMailAction::class)->execute($main);
        });
    }

    /**
     * @return array<string, string>
     */
    public static function fittingTypes(): array
    {
        return [
            'ADL rolstoel' => 'ADL rolstoel',
            'Sport rolstoel' => 'Sport rolstoel',
            'PAWS' => 'PAWS',
            'Handbike' => 'Handbike',
            'Free/Trackwheel' => 'Free/Trackwheel',
            'Overig' => 'Overig',
        ];
    }

    public static function fittingTypeLabel(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::fittingTypes()[$value] ?? $value;
    }

    /**
     * Slotfactuur (onderdeel): eerste keer paid_at gezet → markeer voor mail in {@see static::updated()}.
     */
    protected static function registerPartSlotInvoicePaidNotification(BaseOrder $order): void
    {
        if (! $order->isDirty('paid_at')) {
            return;
        }

        if ($order->paid_at === null) {
            return;
        }

        if ($order->getOriginal('paid_at') !== null) {
            return;
        }

        if ($order->getType() !== OrderType::Invoice) {
            return;
        }

        $mainId = $order->main_id;
        if ($mainId === null) {
            return;
        }

        $main = Main::query()->find($mainId);
        if ($main === null || $main->getSubtype() !== OrderSubtype::Part) {
            return;
        }

        $key = $order->getKey();
        if ($key === null) {
            return;
        }

        static::$pendingPartSlotInvoicePaidNotificationByOrderId[$key] = $mainId;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
        $this->addMediaCollection('images');
        $this->addMediaCollection('quote')->singleFile();
        $this->addMediaCollection('order')->singleFile();
        $this->addMediaCollection('invoice')->singleFile();
        $this->addMediaCollection('deposit_invoice')->singleFile();
        $this->addMediaCollection('credit_invoice')->singleFile();
    }

    public function getUidFormattedAttribute(): string
    {
        return $this->getUidFormatted();
    }

    public function getSpMarginSummaryAttribute(): string
    {
        $companySalesPrice = $this->getCompanySalesPriceTotal();
        $purchasePrice = $this->getCompanyPurchasePriceTotal();
        $margin = $companySalesPrice - $purchasePrice;
        $add = '';
        if ($purchasePrice > 0) {
            $percentage = ($margin / $purchasePrice) * 100;
            $add = ' (' . round($percentage, 1) . '%)';
        }

        return '€ ' . number_format((float)$margin, 2, ',', '.') . $add;
    }

//    public function getCustomerAddress() {
//
//        return $this->billingAddress->getAddressTemplateIncNameFormatted($this->getName());
//    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'main_id', 'id');
    }

    public function ordersNonDraft(): HasMany
    {
        return $this->hasMany(Order::class, 'main_id', 'id')
            ->whereNotIn('status', [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft]);
    }

    public function getLatestExpectedDeliveryDateAttribute()
    {
        return null;
    }


    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function shippingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'shipping_customer_id');
    }

    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }

    /**
     * Billing address for views and PDFs (debtor via {@see $billingCustomer}).
     */
    protected function billingAddress(): Attribute
    {
        return Attribute::get(fn(): ?Address => $this->getBillingAddress());
    }

    /**
     * Shipping address for views, PostNL, and customer mails (includes snapshot when type {@see resolveShippingAddressTypeKey()} is `custom`).
     */
    protected function shippingAddress(): Attribute
    {
        return Attribute::get(fn(): ?Address => $this->resolveShippingAddressAttribute());
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function quoteCompany(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_company_id');
    }

//    public function orderCompany(): BelongsTo
//    {
//        return $this->belongsTo(OrderCompany::class, 'order_company_id');
//    }

    public function getOrderCompanyId(): ?int
    {
        return $this->order_company_id;
    }

//    public function setOrderCompanyId(?int $value): self
//    {
//        $this->order_company_id = $value;
//
//        return $this;
//    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(Main::class, 'main_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(BaseOrder::class, 'main_id', 'id');
    }

    public function fittings(): HasMany
    {
        return $this->hasMany(BaseOrder::class, 'main_id', 'id')
            ->where('type', OrderType::Fitting);
    }

    public function getMain(): ?Main
    {
        return $this->main;
    }

    public function getMainId(): ?int
    {
        return $this->main_id;
    }

    public function setMainId(?int $value): self
    {
        $this->main_id = $value;

        return $this;
    }

    public function getQuoteId(): ?int
    {
        return $this->quote_id;
    }

    public function setQuoteId(?int $value): self
    {
        $this->quote_id = $value;

        return $this;
    }

    public function isMain(): bool
    {
        return $this->type === OrderType::Main;
    }

    public function depositInvoice()
    {
        return $this->belongsTo(DepositInvoice::class, 'deposit_invoice_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function creditInvoice()
    {
        return $this->hasOne(CreditInvoice::class, 'id', 'credit_invoice_id');
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_order_id');
    }

    public function orderEvents(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id', 'id');
    }

    /**
     * Immutable HTML/PDF issuance rows (e.g. sent quote, order confirmation, invoice PDF).
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Load an orders row as the concrete STI model so {@see Model::getMorphClass()} matches
     * how rows are stored when created via subclass instances (e.g. {@see Quote}, {@see Order}).
     *
     * Needed after {@see self::withoutGlobalScopes()} queries that hydrate as {@see BaseOrder}.
     */
    public static function findOrFailTypedWithoutScopes(int|string $id): BaseOrder
    {
        $base = self::withoutGlobalScopes()->findOrFail($id);
        $type = $base->getType();

        return match ($type) {
            OrderType::Quote => Quote::withoutGlobalScopes()->findOrFail($id),
            OrderType::Order => Order::withoutGlobalScopes()->findOrFail($id),
            OrderType::Main => Main::withoutGlobalScopes()->findOrFail($id),
            OrderType::Invoice => Invoice::withoutGlobalScopes()->findOrFail($id),
            OrderType::DepositInvoice => DepositInvoice::withoutGlobalScopes()->findOrFail($id),
            OrderType::CreditInvoice => CreditInvoice::withoutGlobalScopes()->findOrFail($id),
            default => $base,
        };
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class, 'order_id', 'id');
    }

    public function postnlShipments(): HasMany
    {
        return $this->hasMany(\App\Models\PostNLShipment::class, 'order_id', 'id');
    }

    public function orderProducts(): HasMany
    {
        return $this->hasMany(OrderProduct::class, 'order_id')->orderBy('sort');
    }

    public function mtoOrderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id')
            ->where(fn(Builder $query) => $query
                ->where('fulfillment_type', FulfillmentType::MakeToOrder)
                ->orWhereNull('fulfillment_type')
            );
    }

    public function mtsOrderProducts()
    {
        return $this->hasMany(OrderProduct::class, 'order_id')
            ->where('fulfillment_type', FulfillmentType::MakeToStock);
    }

    public function frameProduct(): HasOneThrough
    {
        return $this->hasOneThrough(Product::class, OrderProduct::class, 'order_id', 'id', 'id', 'product_id')
            ->where('products.type', ProductType::Frame->value);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): ?OrderType
    {
        return $this->type;
    }

    public function setType(OrderType|string $value): self
    {
        $this->type = $value;
        return $this;
    }

    public function getStatus(): ?OrderGeneralStatus
    {
        return $this->status;
    }

    public function setStatus(OrderGeneralStatus|string $value): self
    {
        $this->status = $value;
        return $this;
    }

    /**
     * Status value for financial document tables (Financiële documenten, klantdocumenten).
     * Invoice types show {@see OrderGeneralStatus::Sent} only after {@see $this->sent_at} is set.
     */
    public function resolveFinancialDocumentStatusValue(): string
    {
        $statusValue = $this->status instanceof OrderGeneralStatus
            ? $this->status->value
            : (string) ($this->status ?? '');

        $type = $this->getType();
        $typeValue = $type instanceof OrderType ? $type->value : (string) ($type ?? '');

        return self::resolveFinancialDocumentStatusValueFor(
            $typeValue,
            $statusValue,
            $this->getSentAt(),
        );
    }

    public static function resolveFinancialDocumentStatusValueFor(
        string $typeValue,
        string $statusValue,
        ?Carbon $sentAt,
    ): string {
        if (! in_array($typeValue, [
            OrderType::Invoice->value,
            OrderType::DepositInvoice->value,
            OrderType::CreditInvoice->value,
        ], true)) {
            return $statusValue;
        }

        if (in_array($statusValue, [
            OrderGeneralStatus::Draft->value,
            OrderGeneralStatus::Initial->value,
            OrderGeneralStatus::Cancelled->value,
        ], true)) {
            return $statusValue;
        }

        if ($sentAt !== null) {
            return OrderGeneralStatus::Sent->value;
        }

        return OrderGeneralStatus::Pending->value;
    }

    public function getOrderStatus(): ?OrderStatus
    {
        return $this->order_status;
    }

    public function setOrderStatus(OrderStatus|string $value): self
    {
        $this->order_status = $value;
        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $value): self
    {
        $this->uid = $value;
        return $this;
    }

    public function getRev(): int
    {
        return $this->rev;
    }

    public function setRev(int $value): self
    {
        $this->rev = $value;
        return $this;
    }

    public function getSubtype(): ?OrderSubtype
    {
        return $this->subtype;
    }

    public function setSubtype(OrderSubtype|string|null $value): self
    {
        $this->subtype = $value;
        return $this;
    }

    public function getIsTest(): int
    {
        return $this->is_test;
    }

    public function setIsTest(int $value): self
    {
        $this->is_test = $value;
        return $this;
    }

    public function getCompanyPurchasePriceBase(): float
    {
        return (float)$this->company_purchase_price_base;
    }

    public function setCompanyPurchasePriceBase(float $value): self
    {
        $this->company_purchase_price_base = $value;
        return $this;
    }

    public function getCompanyPurchasePriceDiscount(): float
    {
        return (float)$this->company_purchase_price_discount;
    }

    public function setCompanyPurchasePriceDiscount(float $value): self
    {
        $this->company_purchase_price_discount = $value;
        return $this;
    }

    public function getCompanyPurchasePriceTotal(): float
    {
        return (float)$this->company_purchase_price_total;
    }

    public function setCompanyPurchasePriceTotal(float $value): self
    {
        $this->company_purchase_price_total = $value;
        return $this;
    }

    public function getCompanySalesPriceBase(): float
    {
        return (float)$this->company_sales_price_base;
    }

    public function setCompanySalesPriceBase(float $value): self
    {
        $this->company_sales_price_base = $value;
        return $this;
    }

    public function getCompanySalesPriceDiscount(): float
    {
        return (float)$this->company_sales_price_discount;
    }

    public function setCompanySalesPriceDiscount(float $value): self
    {
        $this->company_sales_price_discount = $value;
        return $this;
    }

    public function getCompanySalesPriceTotal(): float
    {
        return (float)$this->company_sales_price_total;
    }

    public function setCompanySalesPriceTotal(float $value): self
    {
        $this->company_sales_price_total = $value;
        return $this;
    }

    public function getPaymentPercentage(): ?float
    {
        return $this->payment_percentage ? (float)$this->payment_percentage : null;
    }

    public function setPaymentPercentage(?float $value): self
    {
        $this->payment_percentage = $value;
        return $this;
    }

    public function getPaymentAmount(): ?float
    {
        return $this->payment_amount ? (float)$this->payment_amount : null;
    }

    public function setPaymentAmount(?float $value): self
    {
        $this->payment_amount = $value;
        return $this;
    }

    public function getDepositAmount(): float
    {
        return (float)$this->deposit_amount;
    }

    public function setDepositAmount(float $value): self
    {
        $this->deposit_amount = $value;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $value): self
    {
        $this->reference = $value;
        return $this;
    }

    public function getReferenceInternal(): ?string
    {
        return $this->reference_internal;
    }

    public function setReferenceInternal(?string $value): self
    {
        $this->reference_internal = $value;

        return $this;
    }

    public function getIsVerified(): int
    {
        return $this->is_verified;
    }

    public function setIsVerified(int $value): self
    {
        $this->is_verified = $value;
        return $this;
    }

    public function getIsForceVerified(): int
    {
        return $this->is_force_verified;
    }

    public function setIsForceVerified(int $value): self
    {
        $this->is_force_verified = $value;
        return $this;
    }

    public function getDiscountComment(): ?string
    {
        return $this->discount_comment;
    }

    public function setDiscountComment(?string $value): self
    {
        $this->discount_comment = $value;
        return $this;
    }

    public function getOrderComment(): ?string
    {
        return $this->order_comment;
    }

    public function setOrderComment(?string $value): self
    {
        $this->order_comment = $value;
        return $this;
    }

    /**
     * Display name for PDF "Verkoper" (sales order author, quote author, order-confirmation event user, etc.).
     */
    public function resolveSellerDisplayName(): ?string
    {
        $this->loadMissing('author');

        $fromUser = function (?User $user): ?string {
            $name = $user?->getName();

            return is_string($name) && trim($name) !== '' ? trim($name) : null;
        };

        if ($name = $fromUser($this->author)) {
            return $name;
        }

        if ($this->getType() === OrderType::Quote && filled($this->getUid())) {
            $quoteWithAuthor = Quote::query()
                ->where('uid', $this->getUid())
                ->whereNotNull('author_id')
                ->orderByDesc('id')
                ->with('author')
                ->first();

            if ($name = $fromUser($quoteWithAuthor?->author)) {
                return $name;
            }
        }

        $main = $this instanceof Main ? $this : $this->main;
        if (! $main instanceof Main) {
            return null;
        }

        $main->loadMissing(['author', 'quotes.author']);

        if ($name = $fromUser($main->author)) {
            return $name;
        }

        $quoteWithAuthorOnMain = $main->quotes()
            ->whereNotNull('author_id')
            ->orderByDesc('id')
            ->with('author')
            ->first();

        if ($name = $fromUser($quoteWithAuthorOnMain?->author)) {
            return $name;
        }

        if ($name = $fromUser($main->quote?->author)) {
            return $name;
        }

        $salesOrderWithAuthor = $main->orders()
            ->whereNotNull('author_id')
            ->orderByDesc('id')
            ->with('author')
            ->first();

        if ($name = $fromUser($salesOrderWithAuthor?->author)) {
            return $name;
        }

        $confirmationEvent = $main->orderEvents()
            ->where('type', 'like', 'Orderbevestiging%')
            ->whereNotNull('user_id')
            ->orderByDesc('id')
            ->with('user')
            ->first();

        if ($name = $fromUser($confirmationEvent?->user)) {
            return $name;
        }

        $parentOrder = $this->order;
        if ($parentOrder instanceof self && $parentOrder->isNot($this)) {
            $parentOrder->loadMissing('author');

            return $fromUser($parentOrder->author);
        }

        return null;
    }

    public function getCustomerId(): ?int
    {
        return $this->customer_id;
    }

    public function setCustomerId(?int $value): self
    {
        $this->customer_id = $value;
        return $this;
    }

    public function getShippingCustomerId(): ?int
    {
        return $this->shipping_customer_id;
    }

    public function setShippingCustomerId(?int $value): self
    {
        $this->shipping_customer_id = $value;
        return $this;
    }

    public function getBillingCustomerId(): ?int
    {
        return $this->billing_customer_id;
    }

    public function setBillingCustomerId(?int $value): self
    {
        $this->billing_customer_id = $value;
        return $this;
    }

    public function getAdvisorId(): ?int
    {
        return $this->advisor_id;
    }

    public function setAdvisorId(?int $value): self
    {
        $this->advisor_id = $value;
        return $this;
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

    /**
     * Subtype key for payment settings ({@see Setting::get('payment.{subtype}.*')}): linked Main’s subtype when present, else this row’s subtype.
     */
    protected function getSubtypeKeyForMainDefaults(): string
    {
        $main = $this instanceof Main ? $this : $this->getMain();
        $subtype = $main !== null ? $main->getSubtype() : $this->getSubtype();

        return match ($subtype) {
            OrderSubtype::Unit => 'unit',
            OrderSubtype::Service => 'service',
            OrderSubtype::Part => 'part',
            default => 'unit',
        };
    }

    /**
     * Billing-party segment for payment settings: matches `CustomerType::value` for invoice customers (not {@see CustomerType::RD}).
     */
    public function getBillingCustomerSegmentKey(): string
    {
        $this->loadMissing(['billingCustomer', 'customer']);

        return $this->getBillingSegmentKeyForOrderProcess($this->billingCustomer ?? $this->customer);
    }

    /**
     * Billing-party segment for payment settings: matches `CustomerType::value` for invoice customers (not {@see CustomerType::RD}).
     */
    protected function getBillingSegmentKeyForOrderProcess(?Customer $invoiceCustomer): string
    {
        return InvoiceReminderSettings::resolveSegmentKey($invoiceCustomer);
    }

    /**
     * Payment terms for billing context: {@see Setting::get('payment.{subtype}.{segment}.payment_terms')}, else legacy fallbacks.
     * If `billing_customer_id` is null, the end customer (`customer`) is the invoice party.
     */
    public function getPaymentTermsValueForBillingContext(): string
    {
        $this->loadMissing(['billingCustomer', 'customer']);

        $invoiceCustomer = $this->billingCustomer ?? $this->customer;
        $segment = $this->getBillingSegmentKeyForOrderProcess($invoiceCustomer);

        $subtypeKey = $this->getSubtypeKeyForMainDefaults();
        $fromSetting = Setting::get("payment.{$subtypeKey}.{$segment}.payment_terms");
        if (is_string($fromSetting) && $fromSetting !== '') {
            return $fromSetting;
        }

        $main = $this instanceof Main ? $this : $this->getMain();
        $mainSubtype = $main !== null ? $main->getSubtype() : $this->getSubtype();
        $isB2CInvoice = $invoiceCustomer?->getType() === CustomerType::B2C;

        if ($isB2CInvoice && $mainSubtype === OrderSubtype::Unit) {
            return PaymentTerms::Split50_50->value;
        }

        return PaymentTerms::Postpay->value;
    }

    /**
     * Persists `payment_terms`, `additional.exact_payment_condition`, and `additional.exact_vat_code` from the current billing context (payment settings).
     */
    public function applyMainDefaultsBillingTermsFromContext(): void
    {
        $value = $this->getPaymentTermsValueForBillingContext();
        $this->payment_terms = PaymentTerms::tryFrom($value) ?? PaymentTerms::Postpay;

        $this->loadMissing(['billingCustomer', 'customer']);
        $invoiceParty = $this->billingCustomer ?? $this->customer;

        $code = $this->resolveDefaultExactPaymentConditionCode();
        $additional = $this->getAdditional() ?? [];
        if (is_string($code) && $code !== '') {
            $additional['exact_payment_condition'] = $code;
        } else {
            unset($additional['exact_payment_condition']);
        }

        if ($invoiceParty !== null) {
            $vatCode = $invoiceParty->getExactVatCode();
            if (is_string($vatCode) && $vatCode !== '') {
                $additional['exact_vat_code'] = $vatCode;
            } else {
                unset($additional['exact_vat_code']);
            }
        }

        $this->setAdditional($additional);
    }

    /**
     * Exact payment condition code for display: {@code orders.additional.exact_payment_condition}, else linked quote/order, else payment settings.
     */
    public function getExactPaymentConditionCodeForView(): string
    {
        $forced = $this->forcedExactPaymentConditionFromPaymentTerms();
        if ($forced !== null) {
            return $forced;
        }

        $additional = $this->getAdditional() ?? [];
        $code = $additional['exact_payment_condition'] ?? null;
        if (is_string($code) && $code !== '') {
            return $code;
        }

        if ($this instanceof Main) {
            $this->loadMissing(['quotes']);

            $quoteFromRel = $this->quotes()->reorder()->orderByDesc('id')->first();
            if ($quoteFromRel !== null) {
                $code = data_get($quoteFromRel->getAdditional(), 'exact_payment_condition');
                if (is_string($code) && $code !== '') {
                    return $code;
                }
            }

            $orderRow = $this->getLastOrder() ?? $this->orders()->orderByDesc('id')->first();
            if ($orderRow !== null) {
                $code = data_get($orderRow->getAdditional(), 'exact_payment_condition');
                if (is_string($code) && $code !== '') {
                    return $code;
                }
            }
        }

        return $this->resolveDefaultExactPaymentConditionCode();
    }

    /**
     * Resolve Exact payment condition for billing: Advance100 → 0D, else customer code, else payment settings.
     */
    public function resolveExactPaymentConditionCodeForBillingContext(?Customer $invoiceCustomer = null): string
    {
        $forced = $this->forcedExactPaymentConditionFromPaymentTerms();
        if ($forced !== null) {
            return $forced;
        }

        $invoiceCustomer ??= $this->billingCustomer ?? $this->customer;
        if ($invoiceCustomer !== null) {
            $customerCode = $invoiceCustomer->getExactPaymentCondition();
            if (is_string($customerCode) && $customerCode !== '') {
                return $customerCode;
            }
        }

        return $this->resolveDefaultExactPaymentConditionCode();
    }

    /**
     * Human-readable payment condition for UI (matches Select option style: "CODE : Name").
     */
    public function getExactPaymentConditionLabelForView(): string
    {
        $code = $this->getExactPaymentConditionCodeForView();
        $row = ExactPaymentCondition::query()->where('code', $code)->first();
        if ($row !== null) {
            return "{$row->code} : {$row->name}";
        }

        return $code;
    }

    protected function resolveDefaultExactPaymentConditionCode(): string
    {
        $forced = $this->forcedExactPaymentConditionFromPaymentTerms();
        if ($forced !== null) {
            return $forced;
        }

        $this->loadMissing(['billingCustomer', 'customer']);

        $invoiceCustomer = $this->billingCustomer ?? $this->customer;
        $segment = $this->getBillingSegmentKeyForOrderProcess($invoiceCustomer);

        $subtypeKey = $this->getSubtypeKeyForMainDefaults();
        $override = Setting::get("payment.{$subtypeKey}.{$segment}.exact_payment_condition");

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $code = Setting::get("exact_payment_condition_by_type.{$segment}");

        if (is_string($code) && $code !== '') {
            return $code;
        }

        return ExactPaymentCondition::DEFAULT_PAYMENT_CONDITION_CODE;
    }

    private function forcedExactPaymentConditionFromPaymentTerms(): ?string
    {
        $terms = $this->payment_terms
            ?? PaymentTerms::tryFrom($this->getPaymentTermsValueForBillingContext());

        return PaymentTerms::forcedExactPaymentConditionCodeFor($terms);
    }

    public function getDeliveryAdvisorId(): ?int
    {
        return $this->delivery_advisor_id;
    }

    public function setDeliveryAdvisorId(?int $value): self
    {
        $this->delivery_advisor_id = $value;
        return $this;
    }

    public function getSupplierId(): ?int
    {
        return $this->supplier_id;
    }

    public function setSupplierId(?int $value): self
    {
        $this->supplier_id = $value;
        return $this;
    }

    public function getOrderId(): ?int
    {
        return $this->order_id;
    }

    public function setOrderId(?int $value): self
    {
        $this->order_id = $value;
        return $this;
    }

    public function getDepositInvoiceId(): ?int
    {
        return $this->deposit_invoice_id;
    }

    public function setDepositInvoiceId(?int $value): self
    {
        $this->deposit_invoice_id = $value;
        return $this;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoice_id;
    }

    public function setInvoiceId(?int $value): self
    {
        $this->invoice_id = $value;
        return $this;
    }

    public function getCreditInvoiceId(): ?int
    {
        return $this->credit_invoice_id;
    }

    public function setCreditInvoiceId(?int $value): self
    {
        $this->credit_invoice_id = $value;
        return $this;
    }

    public function getRecurringOrderId(): ?int
    {
        return $this->recurring_order_id;
    }

    public function setRecurringOrderId(?int $value): self
    {
        $this->recurring_order_id = $value;

        return $this;
    }

    public function getPaymentLinkId(): ?int
    {
        return $this->payment_link_id;
    }

    public function setPaymentLinkId(?int $value): self
    {
        $this->payment_link_id = $value;

        return $this;
    }

    public function getPaidAt(): ?Carbon
    {
        return $this->paid_at;
    }

    public function setPaidAt(?Carbon $value): self
    {
        $this->paid_at = $value;

        return $this;
    }

    public function getPaymentMethod(): ?PaymentMethodType
    {
        return $this->payment_method;
    }

    public function setPaymentMethod(?PaymentMethodType $value): self
    {
        $this->payment_method = $value;

        return $this;
    }

    public function getSentAt(): ?Carbon
    {
        return $this->sent_at;
    }

    public function setSentAt(?Carbon $value): self
    {
        $this->sent_at = $value;

        if ($this->shouldSyncExpiresAtFromPaymentCondition()) {
            if ($value === null) {
                $this->setExpiresAt(null);
            } else {
                $this->syncExpiresAtFromPaymentCondition($value);
            }
        }

        return $this;
    }

    protected function shouldSyncExpiresAtFromPaymentCondition(): bool
    {
        return in_array($this->getType(), [
            OrderType::Invoice,
            OrderType::DepositInvoice,
            OrderType::CreditInvoice,
        ], true);
    }

    /**
     * Exact payment condition code used to derive invoice due date (own row, parent order, then billing context).
     */
    public function resolveExactPaymentConditionCodeForDueDate(): string
    {
        $forced = $this->forcedExactPaymentConditionFromPaymentTerms();
        if ($forced !== null) {
            return $forced;
        }

        $code = data_get($this->getAdditional(), 'exact_payment_condition');
        if (is_string($code) && $code !== '') {
            return $code;
        }

        $this->loadMissing('order');
        if ($this->order !== null) {
            $parentCode = data_get($this->order->getAdditional(), 'exact_payment_condition');
            if (is_string($parentCode) && $parentCode !== '') {
                return $parentCode;
            }
        }

        return $this->resolveExactPaymentConditionCodeForBillingContext();
    }

    public function resolvePaymentDaysForBillingContext(): int
    {
        $code = $this->resolveExactPaymentConditionCodeForDueDate();
        $row = ExactPaymentCondition::query()->where('code', $code)->first();

        if ($row === null) {
            throw new InvalidArgumentException(
                'Onbekende betalingsconditie (exact_payment_condition): '.$code
            );
        }

        return max(0, (int) $row->payment_days);
    }

    public function syncExpiresAtFromPaymentCondition(?Carbon $from = null): self
    {
        $from ??= $this->getSentAt() ?? now();

        return $this->setExpiresAt(
            $from->copy()->startOfDay()->addDays($this->resolvePaymentDaysForBillingContext())
        );
    }

    public function resolveDueDateForExact(): string
    {
        $expiresAt = $this->getExpiresAt();
        if ($expiresAt !== null) {
            return $expiresAt->format('Y-m-d');
        }

        $from = $this->getSentAt() ?? now();

        return $from->copy()->startOfDay()
            ->addDays($this->resolvePaymentDaysForBillingContext())
            ->format('Y-m-d');
    }

    /**
     * Return the existing public download UUID, or generate and persist one for PDF links on the quote host.
     */
    public function getOrCreatePublicDownloadUuid(): string
    {
        if (filled($this->public_download_uuid)) {
            return (string)$this->public_download_uuid;
        }

        $uuid = (string)Str::uuid();
        $this->forceFill(['public_download_uuid' => $uuid])->saveQuietly();
        $this->public_download_uuid = $uuid;

        return $uuid;
    }

    public function getExpiresAt(): ?Carbon
    {
        return $this->expires_at;
    }

    public function setExpiresAt(?Carbon $value): self
    {
        $this->expires_at = $value;
        return $this;
    }

    public function getFirstReminderSentAt(): ?Carbon
    {
        return $this->first_reminder_sent_at;
    }

    public function setFirstReminderSentAt(?Carbon $value): self
    {
        $this->first_reminder_sent_at = $value;

        return $this;
    }

    public function getSecondReminderSentAt(): ?Carbon
    {
        return $this->second_reminder_sent_at;
    }

    public function setSecondReminderSentAt(?Carbon $value): self
    {
        $this->second_reminder_sent_at = $value;

        return $this;
    }

    /**
     * Calculate expires_at. Currently only used for quotes
     */
    public function calculateExpiresAt(): CarbonInterface
    {
        return now()->addDays($this->validity_period->value ?? 60);
    }

    public function getExactId(): ?string
    {
        return $this->exact_id;
    }

    public function setExactId(?string $value): self
    {
        $this->exact_id = $value;
        return $this;
    }

    public function getExactSyncedAt(): ?Carbon
    {
        return $this->exact_synced_at;
    }

    public function setExactSyncedAt(?Carbon $value): self
    {
        $this->exact_synced_at = $value;
        return $this;
    }

    public function getExactErrorAt(): ?Carbon
    {
        return $this->exact_error_at;
    }

    public function setExactErrorAt(?Carbon $value): self
    {
        $this->exact_error_at = $value;
        return $this;
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

    /**
     * Recipient based on the billing customer.
     *
     * @return array{0: string|null, 1: string}
     */
    public function getBillingRecipient(): array
    {
        $this->loadMissing('billingCustomer');

        return [$this->billingCustomer?->getEmail(), $this->billingCustomer?->getName() ?? ''];
    }

    /**
     * Display name for the billing address line.
     */
    public function getBillingInvoiceDisplayName(): string
    {
        $this->loadMissing('billingCustomer');

        $name = $this->billingCustomer?->getName();
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $billingAddr = $this->getBillingAddress();
        $fromBillingAddr = $billingAddr?->getName();

        return is_string($fromBillingAddr) ? trim($fromBillingAddr) : '';
    }

    public const string DEALER_MAIL_SALUTATION_FALLBACK = 'heer/mevrouw';

    /**
     * Contact name from the invoice "Ter attentie van" field (order snapshot or billing address).
     */
    public function resolveDealerInvoiceAttentionName(): string
    {
        $this->loadMissing('billingCustomer.billingAddress');

        $additional = $this->getAdditional() ?? [];

        $fromSnapshot = trim((string) ($additional['invoice_address']['name'] ?? ''));
        if ($fromSnapshot !== '') {
            return $fromSnapshot;
        }

        $fromBillingAddress = trim((string) ($this->getBillingAddress()?->getName() ?? ''));
        if ($fromBillingAddress !== '') {
            return $fromBillingAddress;
        }

        $billingNameLine = $additional['billing_name'] ?? null;
        if (is_string($billingNameLine)) {
            $parsed = $this->parseTerAttentieVanValue($billingNameLine);
            if ($parsed !== '') {
                return $parsed;
            }
        }

        return '';
    }

    /**
     * Contact name from the location / shipping "Ter attentie van" field (snapshot or shipping address).
     */
    public function resolveDealerLocationAttentionName(): string
    {
        $this->loadMissing(['shippingCustomer', 'shippingAddress', 'customer.shippingAddress']);

        $additional = $this->getAdditional() ?? [];

        $shippingNameLine = $additional['shipping_name'] ?? null;
        if (is_string($shippingNameLine)) {
            $parsed = $this->parseTerAttentieVanValue($shippingNameLine);
            if ($parsed !== '') {
                return $parsed;
            }
        }

        $delivery = $additional['delivery_address'] ?? null;
        if (is_array($delivery)) {
            $fromDelivery = trim((string) ($delivery['name'] ?? ''));
            if ($fromDelivery !== '') {
                return $fromDelivery;
            }
        }

        $fromShippingAddress = trim((string) ($this->getShippingAddress()?->getName() ?? ''));
        if ($fromShippingAddress !== '') {
            return $fromShippingAddress;
        }

        if ($this->getCustomerAddressType() === CustomerAddressType::Shipping) {
            $fromCustomerAddress = trim((string) ($this->getCustomerAddress()?->getName() ?? ''));
            if ($fromCustomerAddress !== '') {
                return $fromCustomerAddress;
            }
        }

        return '';
    }

    /**
     * Salutation for dealer e-mails: invoice or location attention name, or {@see DEALER_MAIL_SALUTATION_FALLBACK}.
     */
    public function resolveDealerMailSalutation(): string
    {
        $attention = $this->resolveDealerInvoiceAttentionName();
        if ($attention === '') {
            $attention = $this->resolveDealerLocationAttentionName();
        }

        return $attention !== '' ? $attention : self::DEALER_MAIL_SALUTATION_FALLBACK;
    }

    private function parseTerAttentieVanValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^Ter attentie van:\s*(.+)$/iu', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }

    public function getQuoteCreatedAt(): ?Carbon
    {
        return $this->quote_created_at;
    }

    public function setQuoteCreatedAt(?Carbon $value): self
    {
        $this->quote_created_at = $value;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFittingNote(): ?array
    {
        return $this->fitting_note;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setFittingNote(?array $value): self
    {
        $this->fitting_note = $value;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDeliveryNote(): ?array
    {
        return $this->delivery_note;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setDeliveryNote(?array $value): self
    {
        $this->delivery_note = $value;

        return $this;
    }

    public function getServiceNote(): ?array
    {
        return $this->service_note;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setServiceNote(?array $value): self
    {
        $this->service_note = $value;

        return $this;
    }

    public function getCancelledAt(): ?Carbon
    {
        return $this->cancelled_at;
    }

    public function setCancelledAt(?Carbon $value): self
    {
        $this->cancelled_at = $value;
        return $this;
    }

    public function getIsCancelled(): ?int
    {
        return $this->is_cancelled;
    }

    public function setIsCancelled(?int $value): self
    {
        $this->is_cancelled = $value;
        return $this;
    }

    public function getIsCancellationCreditInvoice(): int
    {
        return $this->is_cancellation_credit_invoice;
    }

    public function setIsCancellationCreditInvoice(int $value): self
    {
        $this->is_cancellation_credit_invoice = $value;
        return $this;
    }

    public function getCancelComment(): ?string
    {
        return $this->cancel_comment;
    }

    public function setCancelComment(?string $value): self
    {
        $this->cancel_comment = $value;
        return $this;
    }


    /**
     * Determine if the order can be edited.
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return !in_array($this->type, ['order', 'invoice'])
            || !in_array($this->status, ['completed', 'expired']);
    }

    public function getDeliveryDate(): string
    {
        $deliveryWeek = $this->getDeliveryWeek();

        if ($deliveryWeek) {
            $deliveryYear = date('Y');
            return date('Y-m-d', strtotime($deliveryYear . 'W' . $deliveryWeek));
        }

        // default six weeks delivery time
        return now()->addWeeks(6)->format('Y-m-d');
    }

    public function addToSync(): SyncJob
    {
        return $this->syncJob()->create();
    }

    public function isInvoicePaid(): bool
    {
        return $this->getPaidAt() !== null;
    }

    public function getPaymentLink(): ?string
    {
        $url = $this->paymentLink?->link;
        if (!is_string($url) || $url === '') {
            return null;
        }

        return $url;
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            OrderType::Quote => 'Offerte',
            OrderType::Order => 'Order',
            OrderType::Invoice => 'Factuur',
            OrderType::DepositInvoice => 'Aanbetaling',
            OrderType::CreditInvoice => 'Creditfactuur',
            default => 'Onbekend',
        };
    }

    public function getFilename(): string
    {
        return match ($this->type) {
                OrderType::Quote => 'offerte',
                OrderType::Order => 'order',
                OrderType::Invoice, OrderType::DepositInvoice => 'factuur',
                OrderType::CreditInvoice => 'creditfactuur',
                default => '',
            } . '_' . $this->getUidFormatted();
    }


    /**
     * Compares status with current status
     *
     * @param string $status
     * @return bool
     */
    public function isStatus(string $status): bool
    {
        return $this->status === $status;
    }

    public function isDraft(): bool
    {
        return $this->getStatus() === OrderGeneralStatus::Draft;
    }

    public function isDraftQuote(): bool
    {
        return $this->type === OrderType::Quote && $this->getStatus() === OrderGeneralStatus::Draft && empty($this->getUid());
    }

    public function getTypeName(): string
    {
        return 'Order';
    }

    public function getCalculatedPayableAmount(): float
    {
        return $this->getCompanySalesPriceTotalIncVat() - $this->getDepositAmount();
    }

    public function getOrderDepositAmount(): int
    {
        return $this->order?->depositInvoice?->getDepositAmount() ?? 0;
    }

    public function totalPriceExDeposit(): float
    {
        if ($this->type === 'invoice') {
            return $this->getCompanySalesPriceTotalIncVat() - $this->getOrderDepositAmount();
        }

        return $this->getCompanySalesPriceTotalIncVat();
    }

    public function isExpired(): bool
    {
        return $this->getExpiresAt()
            && $this->getExpiresAt()->isPast();
    }

    public function getTotalPriceCompanyIncVat(): float
    {
        return self::incVat($this->getCompanySalesPriceBase() ?: $this->getCompanySalesPriceTotal()) + $this->getCompanySalesPriceDiscount();
    }

    public function getCartTotalSpMarginAmount(float $discountAmount = 0.0): float
    {
        $totalMargin = $this->orderProducts
            ->sum(function ($item) {
                $salesTotal = $item->getCompanySalesPriceTotal() ?? 0;
                $purchaseTotal = $item->getCompanyPurchasePriceSubtotal() ?? 0;
                return ($salesTotal - $purchaseTotal) * $item->qty;
            });

        return $totalMargin - abs($discountAmount);
    }

    public function getCartTotalSpMarginPercentage(float $discountAmount = 0.0): float
    {
        $totalPurchase = $this->orderProducts
            ->sum(fn($item) => ($item->getCompanyPurchasePriceSubtotal() ?? 0) * $item->qty);

        $totalMarginAmount = $this->getCartTotalSpMarginAmount($discountAmount);

        if ((int)$totalPurchase === 0) {
            return 0.0;
        }

        return ($totalMarginAmount / $totalPurchase) * 100;
    }


    public function getVatAmount(): float
    {
        return $this->getCompanySalesPriceTotalIncVat() - $this->getCompanySalesPriceTotal();
    }

    public function getVisibleOrderProductCount(): int
    {
        return $this->orderProducts()
            ->count();
    }

    /**
     * For order events: visible line count, total incl. VAT, optional amount still due (final invoice: {@see getPaymentAmount()}).
     */
    public function describeTotalsForOrderEvent(float $totalInclVat, ?float $outstandingInclVat = null): string
    {
        $count = $this->getVisibleOrderProductCount();
        $articleLabel = $count === 1 ? 'artikel' : 'artikelen';
        $formatted = number_format($totalInclVat, 2, ',', '.');

        if ($outstandingInclVat === null) {
            return sprintf(' (%d %s, totaal €%s incl.)', $count, $articleLabel, $formatted);
        }

        $outstandingFormatted = number_format($outstandingInclVat, 2, ',', '.');

        return sprintf(' (%d %s, totaal €%s incl., openstaand €%s incl.)', $count, $articleLabel, $formatted, $outstandingFormatted);
    }

    public function getUidFormatted(): string
    {
        $uid = (string)($this->getUid() ?? '');
        if ($uid === '') {
            return '';
        }

        if ($this->getType() === OrderType::Quote && $this->getRev() >= 1) {
            return $uid . ' / ' . $this->getRev();
        }

        if (! in_array($this->getType(), [OrderType::Quote, OrderType::Order], true)
            && $this->getRev() > 1) {
            return $uid . '/' . $this->getRev();
        }

        return $uid;
    }

    /**
     * Order UID with revision suffix for Financiële documenten only (e.g. "31053 / 2").
     */
    public function getUidFormattedWithRevision(): string
    {
        $uid = (string) ($this->getUid() ?? '');
        if ($uid === '') {
            return '';
        }

        if ($this->getType() === OrderType::Order) {
            return $uid . ' / ' . ($this->getRev() + 1);
        }

        return $this->getUidFormatted();
    }

    public function getOrderRef(): bool|string
    {
        return false;
    }

    public function generateDoc(bool $force = false): void
    {
        // todo: remove
        return;
    }


    /**
     * @throws Throwable
     */
    public function sendInvoiceMail(): void
    {
        if ($this->getSentAt()) {
            info('not sending email for invoice: ' . $this->getId()
                . ', already sent at ' . $this->getSentAt());
            return;
        }

        if (empty($this->getUid())) {
            info('not sending email for invoice: ' . $this->getId() . ', no UID found');
            return;
        }

        if ($this->getType() === OrderType::DepositInvoice) {
            $parentOrder = $this->order;
            if ($parentOrder !== null) {
                app(SendDepositInvoiceMailAction::class)->execute($parentOrder);
            } else {
                Log::warning('send_invoice_mail.deposit_missing_parent_order', [
                    'deposit_invoice_id' => $this->getId(),
                ]);
            }

            return;
        }

        $this->setSentAt(now());
        if (in_array($this->getType(), [OrderType::Invoice, OrderType::CreditInvoice], true)) {
            $this->setStatus(OrderGeneralStatus::Sent);
        }
        $this->save();

        if ($this->getType() === OrderType::Invoice) {
            $invoice = Invoice::query()->find($this->getId());

            app()->makeWith(SendInvoiceMailAction::class, ['invoice' => $invoice])
                ->execute();
        }

        if ($this->getType() === OrderType::CreditInvoice) {
            $invoice = CreditInvoice::query()->find($this->getId());
            app(SendCreditInvoiceMailAction::class)->execute(invoice: $invoice);
        }
    }

    public function cancelOrder(array $invoicesToCredit): bool
    {
        foreach ($invoicesToCredit as $item) {
            $success = false;

            try {
                /** @var Invoice $invoice */
                $invoice = Invoice::where('uid', $item)
                    ->where('order_id', $this->getId())
                    ->first();

                // Credit the entire invoice
                if ($invoice) {
                    $creditInvoice = $invoice->createFullCreditInvoice();
                    $creditInvoice->setIsCancellationCreditInvoice(true);
                    $creditInvoice->save();
                    $success = true;
                }

                // TODO: handle stock
                // $inventoryService = app()->make(InventoryService::class);
                // $inventoryService->releaseReservationForOrder($this);
            } catch (Throwable $e) {
                report($e->getMessage());
            }

            if (!$success) {
                return false;
            }
        }

        $this->setOrderStatus(OrderStatus::Cancelled);
        $this->setIsCancelled(true);
        $this->save();

        return true;
    }

    /**
     * @throws Throwable
     */
    public function sendCancellation(): bool
    {
        if (empty($this->getUid())) {
            info('not sending cancellation email for order: ' . $this->getId() . ', no UID found');
            return false;
        }

        // Attach credit invoice(s)
        $creditInvoices = CreditInvoice::where('order_id', $this->getId())
            ->where('is_cancellation_credit_invoice', true)
            ->whereNull('exact_error_at')
            ->whereNotIn('status', [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft])
            ->get();

        if ($creditInvoices->isNotEmpty() && $creditInvoices->contains(fn($invoice) => empty($invoice->uid))) {
            info('not sending cancellation email for order: ' . $this->getId() . ', waiting for credit invoices to receive a UID');
            return false;
        }

        // Send cancellation email to invoice customer
        (new SendCompanyOrderCancellationMailAction($this, $creditInvoices, $this->billingCustomer?->getEmail() ?? ''))->execute();

        // Send cancellation email to SP
        (new SendSpOrderCancellationMailAction($this, null))->execute();

        $creditInvoices->each(function ($creditInvoice) {
            $creditInvoice->setSentAt(now());
            $creditInvoice->save();
        });

        $this->setCancelledAt(now());
        $this->save();

        return true;
    }


    public function getIsAdminGenerated()
    {
        return false;
    }

    /**
     * Create a duplicate of the current Order, including all its associated OrderProducts.
     *
     * @return self The newly duplicated order.
     *
     * @throws OrderNotDuplicatedException|Throwable
     */
    public function duplicate($keepMargins = true): self
    {
        DB::beginTransaction();

        try {
            $newOrder = $this->replicate();
            $newOrder->setSentAt(null);
            $newOrder->public_download_uuid = null;
            $newOrder->save();

            foreach ($this->orderProducts as $orderProduct) {
                $newOrderProduct = $orderProduct->replicate()
                    ->setOrderId($newOrder->getId());

                $newOrderProduct->save();

                // Copy media for the parent order product using Spatie's copy method
                try {
                    foreach ($orderProduct->media as $media) {
                        $media->copy($newOrderProduct, $media->collection_name);
                    }
                } catch (Throwable $e) {
                    Log::error('Error copying media for order product ID ' . $orderProduct->getId() . ', proceeding: ' . (string)$e);
                }

            }

            DB::commit();

            return $newOrder;
        } catch (Exception $e) {
            DB::rollBack();
            throw new OrderNotDuplicatedException($e->getMessage());
        }
    }

    /**
     * Generate a unique UID for this order. UIDs are unique per order type
     * (Fitting, Quote, Order); the scheme follows this order's type.
     *
     * @throws Exception
     */
    public function getNewUid(): ?string
    {
        switch ($this->type) {
            case OrderType::Quote:
                if ($this->billingCustomer?->getIsTest() ?? false) {
                    return 'TEST-' . date('Y-m') . '-' . $this->getId();
                }

                $digits = (int)config('document_uids.quote.digits', 5);
                $start = (int)config('document_uids.quote.start', 10_000);
                $pattern = '^[0-9]{' . $digits . '}$';

                $maxNr = (int)static::withoutGlobalScopes()
                    ->where('type', OrderType::Quote)
                    ->whereNotNull('uid')
                    ->whereNot('uid', 'like', 'TEST-%')
                    ->whereRaw('uid REGEXP ?', [$pattern])
                    ->selectRaw('COALESCE(MAX(CAST(uid AS UNSIGNED)), 0) as max_nr')
                    ->value('max_nr');

                $next = max($maxNr + 1, $start);

                return str_pad((string)$next, $digits, '0', STR_PAD_LEFT);

            case OrderType::Order:
                if ($this->getIsTest()) {
                    return 'TEST-' . date('Y-m') . '-' .
                        $this->getId();
                }

                $digits = (int)config('document_uids.order.digits', 5);
                $start = (int)config('document_uids.order.start', 30_000);
                $pattern = '^[0-9]{' . $digits . '}$';

                $maxNr = (int)static::withoutGlobalScopes()
                    ->where('type', OrderType::Order)
                    ->whereNotNull('uid')
                    ->whereNot('uid', 'like', 'TEST-%')
                    ->whereRaw('uid REGEXP ?', [$pattern])
                    ->selectRaw('COALESCE(MAX(CAST(uid AS UNSIGNED)), 0) as max_nr')
                    ->value('max_nr');

                $next = max($maxNr + 1, $start);

                return str_pad((string)$next, $digits, '0', STR_PAD_LEFT);

            case OrderType::DepositInvoice:
            case OrderType::Invoice:
            case OrderType::CreditInvoice:
                if ($this->getIsTest()) {
                    return 'TEST-' . date('Y-m') . '-' . $this->getId();
                }

                $start = (int)config('document_uids.invoice.start', 26_400_001);
                $minWidth = max(1, strlen((string)$start));

                $maxUid = (int)static::withoutGlobalScopes()
                    ->whereIn('type', [OrderType::DepositInvoice, OrderType::Invoice, OrderType::CreditInvoice])
                    ->whereNotNull('uid')
                    ->whereNot('uid', 'like', 'TEST-%')
                    ->whereRaw('uid REGEXP ?', ['^[0-9]+$'])
                    ->selectRaw('COALESCE(MAX(CAST(uid AS UNSIGNED)), 0) as max_uid')
                    ->value('max_uid');

                $next = max($maxUid + 1, $start);
                $width = max($minWidth, strlen((string)$next));

                return str_pad((string)$next, $width, '0', STR_PAD_LEFT);
        }

        return null;
    }

    public static function getDoc(): string
    {
        return '';
    }

    public static function incVat($price, $vat = false): float
    {
        if ($vat === false) {
            $vat = self::VAT_PERCENTAGE;
        }

        return (float)$price * (1 + ($vat / 100));
    }

    public static function exVat($price, $vat = false): float
    {
        if ($vat === false) {
            $vat = self::VAT_PERCENTAGE;
        }
        return (float)$price / (1 + ($vat / 100));
    }

    /**
     * Scope a query to only include orders of a given status.
     *
     * @param Builder $query
     * @param mixed $status
     * @return Builder
     */
    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include orders of a given type.
     *
     * @param Builder $query
     * @param mixed $type
     * @return Builder
     */
    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @throws Throwable
     */
    public function sync(): bool
    {
        $syncJob = SyncJob::where('order_id', $this->getId())->firstOrFail();
        return (new OrderSyncService($syncJob))->process();
    }


    /**
     * @return \Illuminate\Support\Collection<int, BaseOrder>
     */
    public function getSavedDocuments(): \Illuminate\Support\Collection
    {
        $documents = collect();

        // Check if the current order has a doc_path
        if (!empty($this->doc_path) && Storage::disk('public')->exists($this->doc_path)) {
            $documents->push($this->doc_path);
        }

        // Check related entities for doc_path
        $relatedEntities = [
            // $this->quote,
            // $this->order,
            // $this->orderCompany,
            $this->depositInvoice,
            $this->invoice,
            $this->creditInvoice,
            $this->depositInvoice?->creditInvoice,
            $this->invoice?->creditInvoice,
        ];

        foreach ($relatedEntities as $entity) {
            if ($entity && !empty($entity->doc_path) && Storage::disk('public')->exists($entity->doc_path)) {
                $documents->push($entity);
            }
        }

        return $documents;
    }

    /**
     * Generate and save the PDF for this order's doc to storage and set the doc_path.
     * Returns the storage path on success or null on failure.
     */
    public function saveDocToStorage(string $disk = 'public'): ?string
    {
        try {
            if (empty($this->doc)) {
                $this->generateDoc();
                $this->save();
            }

            if (empty($this->doc)) {
                return null;
            }

            $pdf = PDF::loadHTML($this->getDoc())
                ->setOption('margin-top', $this->getPdfSettings('margin-top', 'invoice'))
                ->setOption('margin-left', 0)
                ->setOption('margin-right', 0)
                ->setOption('header-html', $this->getPdfSettings('header-html', 'invoice'))
                ->setOption('header-spacing', $this->getPdfSettings('header-spacing', 'invoice'));

            $content = $pdf->output();

            $uuid = Str::uuid()->toString();
            $filename = $uuid . '.pdf';
            $path = 'documents/' . $this->type . '/' . $filename;

            Storage::disk($disk)->put($path, $content);

            $this->setDocId($uuid);
            $this->setDocPath($path);
            $this->save();

            return $path;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Delete the PDF for this order's doc from storage and clear the doc_path.
     * Returns true on success or false on failure.
     */
    public function deleteDocFromStorage(string $disk = 'public'): bool
    {
        try {
            if (empty($this->doc_path)) {
                return false;
            }

            if (Storage::disk($disk)->exists($this->doc_path)) {
                Storage::disk($disk)->delete($this->doc_path);
            }

            $this->setDocId(null);
            $this->setDocPath(null);
            $this->save();

            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    public static function subtractDiscountExVat(float $amount, float $discountAmount): float
    {
        if ($discountAmount <> 0)
            // Add because discount_amount is a negative amount in the DB
            return $amount + self::exVat($discountAmount);
        return $amount;
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     *
     * @param string|Expression $column
     * @return $this
     */
    public static function latest($column = null)
    {
        $model = (new static());
        if (is_null($column)) {
            $column = $model->getCreatedAtColumn() ?? 'created_at';
        }

        return $model->newQuery()
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->latest($column);
    }

    public function createCreditInvoice(): CreditInvoice
    {
        $credit = $this->duplicate(false, false)
            ->setSentAt(null)
            ->setExpiresAt(null)
            ->setCreditInvoiceId(null)
            ->setType('credit_invoice')
            ->setStatus(OrderGeneralStatus::Initial)
            ->setUid(null)
            ->setInvoiceId($this->id)
            ->setExactId(null)
            ->setExactSyncedAt(null)
            ->setExactErrorAt(null);

        // Do not inherit deposit/final invoice caption from the credited invoice.
        $credit->caption = null;

        $credit->save();

        // Load as proper CreditInvoice model
        /* @var CreditInvoice $credit * */
        $credit = CreditInvoice::where('id', $credit->getId())->first();
        $credit->refresh();

        $this->applyPaymentPercentageToOrderProducts($credit);
        return $credit;
    }

    public function createDealerInvoice($type = 'order')
    {
        switch ($this->getCompany()->invoice_platform) {
            // case 'moneybird': not implemented yet
            //     return MoneyBird::createInvoice($this);
            default:
                return false;
        }
    }

    public function updateOrderStatusAndLog(OrderStatus $newStatus): void
    {
        $oldStatus = $this->getOrderStatus() ?? null;
        if ($oldStatus !== $newStatus) {
            $this->setOrderStatus($newStatus);
            $this->save();

            // Normalize to strings
            $fromStr = $oldStatus instanceof OrderStatus ? $oldStatus->value : $oldStatus;
            $toStr = $newStatus instanceof OrderStatus ? $newStatus->value : $newStatus;

            // Log status change
            OrderStatusChange::create([
                'order_id' => $this->getId(),
                'from_status' => $fromStr,
                'to_status' => $toStr,
                'changed_by' => auth()?->id(),
                'meta' => null,
            ]);
        }
    }

    /**
     * Total sales price incl. VAT from order lines (each line uses its configured VAT rate).
     * The previous implementation applied {@see incVat} to the order total and therefore always used {@see VAT_PERCENTAGE} (21%).
     */
    public function getCompanySalesPriceTotalIncVat(): float
    {
        $this->loadMissing('orderProducts');

        if ($this->orderProducts->isEmpty()) {
            return self::incVat($this->getCompanySalesPriceTotal());
        }

        $isCreditInvoice = $this->getType() === OrderType::CreditInvoice;

        $sum = 0.0;
        foreach ($this->orderProducts as $orderProduct) {
            if ($isCreditInvoice && ! $orderProduct->getHasCredit()) {
                continue;
            }

            $sum += self::incVat((float) $orderProduct->getCompanySalesPriceTotal(), $orderProduct->getVat());
        }

        if (abs($this->getCompanySalesPriceDiscount()) > 0.00001) {
            $sum += self::incVat($this->getCompanySalesPriceDiscount());
        }

        return $sum;
    }

    public function applyPaymentPercentageToOrderProducts(BaseOrder $order): void
    {
        $percentage = (float)($order->getPaymentPercentage() ?? 100);
        if ($percentage >= 100) {
            return;
        }

        $factor = round($percentage / 100, 4);
        $priceColumns = [
            'company_purchase_price_base',
            'company_purchase_price_additional',
            'company_sales_price_base',
            'company_sales_price_additional',
        ];

        foreach ($order->orderProducts as $orderProduct) {
            foreach ($priceColumns as $col) {
                $val = $orderProduct->{$col} ?? null;
                if ($val !== null && $val !== '') {
                    $orderProduct->{$col} = round((float)$val * $factor, 4);
                }
            }
            $orderProduct->save();
        }
    }

    public function getCalculatedCompanyMarginAmountBase(): float
    {
        return $this->getCompanySalesPriceBase() - $this->getCompanyPurchasePriceTotal();
    }

    public function getCalculatedCompanyMarginAmountTotal(): float
    {
        return $this->getCompanySalesPriceTotal() - $this->getCompanyPurchasePriceTotal();
    }

    public function getCalculatedCompanyMarginPercentageBase(): ?float
    {
        if ($this->getCompanyPurchasePriceTotal() == 0) {
            return null;
        }
        // Margin % = profit as a percentage of purchase cost (same formula as Total)
        return (($this->getCompanySalesPriceBase() / $this->getCompanyPurchasePriceTotal()) - 1) * 100;
    }

    public function getCalculatedCompanyMarginPercentageTotal(): ?float
    {
        if ($this->getCompanyPurchasePriceTotal() == 0) {
            return null;
        }
        return (($this->getCompanySalesPriceTotal() / $this->getCompanyPurchasePriceTotal()) - 1) * 100;
    }

    public function getPdfSettings($setting, $typeOverride = null): mixed
    {
        $type = $typeOverride ?? $this->getType();
        $typeValue = $type?->value ?? $type;

        $settings = [
            'margin-top' => 8,
            'header-html' => null,
            'header-spacing' => null,
        ];

        if (in_array($typeValue, ['invoice', 'deposit_invoice', 'credit_invoice'])) {
            $settings = [
                'margin-top' => null,
                'header-html' => url('/invoice-header'),
                'header-spacing' => 8,
            ];

        }

        return $settings[$setting];
    }

    public function getValidityPeriod(): ?ValidityPeriod
    {
        return $this->validity_period;
    }

    public function setValidityPeriod(?ValidityPeriod $validity_period): void
    {
        $this->validity_period = $validity_period;
    }

    public function getFittingMeasurements(): ?array
    {
        return $this->fitting_measurements;
    }

    public function setFittingMeasurements(?array $fitting_measurements): void
    {
        $this->fitting_measurements = $fitting_measurements;
    }

    public function getChecklist(): ?array
    {
        return $this->checklist;
    }

    public function setChecklist(?array $checklist): void
    {
        $this->checklist = $checklist;
    }

    /**
     * Whether the assembly checklist has an "Eindcontrole" row checked (timestamp filled).
     * Same rules as {@see \App\Http\Livewire\ChecklistTable::isFinalCheckRow()}.
     */
    public function checklistHasCompletedEindcontrole(): bool
    {
        $checklist = $this->getChecklist();
        if (!is_array($checklist) || $checklist === []) {
            return false;
        }

        foreach ($checklist as $row) {
            if (!is_array($row)) {
                continue;
            }
            $description = mb_strtolower(trim((string)($row['description'] ?? '')));
            if ($description !== 'eindcontrole') {
                continue;
            }
            $rawDate = trim((string)($row['checked_at'] ?? ($row['date'] ?? '')));

            return $rawDate !== '';
        }

        return false;
    }

    public function getAdvisor(): ?User
    {
        return $this->advisor;
    }

    public function setAdvisor(?User $advisor): void
    {
        $this->advisor = $advisor;
    }

    public function getCustomerAddressType(): CustomerAddressType
    {
        $value = $this->customer_address_type;

        if ($value instanceof CustomerAddressType) {
            return $value;
        }

        return CustomerAddressType::tryFrom((string) $value) ?? CustomerAddressType::Billing;
    }

    public function setCustomerAddressType(CustomerAddressType|string $value): self
    {
        $this->customer_address_type = $value;

        return $this;
    }

    public function getBillingAddress(): ?Address
    {
        $this->loadMissing([
            'billingCustomer.billingAddress',
            'billingCustomer.address',
            'customer.billingAddress',
            'customer.address',
        ]);

        $party = $this->billingCustomer ?? $this->customer;

        return $party?->getInvoiceAddress();
    }

    /**
     * Display name to use alongside {@see getCustomerAddress()}.
     * When address type is shipping and the shipping address has a location name, that is returned instead of the customer name.
     */
    public function getCustomerAddressDisplayName(): string
    {
        if ($this->getCustomerAddressType() === CustomerAddressType::Shipping) {
            $locationName = $this->getCustomerAddress()?->getLocationName();
            if (filled($locationName)) {
                return $locationName;
            }
        }

        return $this->customer?->getName() ?? '';
    }

    /**
     * Email to use for the customer contact.
     * When address type is shipping and the shipping address has an email, that is returned instead of the customer email.
     */
    public function getCustomerContactEmail(): string
    {
        if ($this->getCustomerAddressType() === CustomerAddressType::Shipping) {
            $email = $this->getCustomerAddress()?->getEmail();
            if (filled($email)) {
                return $email;
            }
        }

        return $this->customer?->getEmail() ?? '';
    }

    /**
     * Phone number to use for the customer contact.
     * When address type is shipping and the shipping address has a phone number, that is returned instead of the customer phone.
     */
    public function getCustomerContactPhone(): string
    {
        if ($this->getCustomerAddressType() === CustomerAddressType::Shipping) {
            $phone = $this->getCustomerAddress()?->phone_number;
            if (filled($phone)) {
                return $phone;
            }
        }

        return $this->customer?->getPhoneNumber() ?? '';
    }

    /**
     * Mobile phone number to use for the customer contact.
     * When address type is shipping and the shipping address has a mobile number, that is returned instead of the customer mobile.
     */
    public function getCustomerContactMobile(): string
    {
        if ($this->getCustomerAddressType() === CustomerAddressType::Shipping) {
            $mobile = $this->getCustomerAddress()?->mobile_phone_number;
            if (filled($mobile)) {
                return $mobile;
            }
        }

        return $this->customer?->getMobilePhoneNumber() ?? '';
    }

    /**
     * Resolves the customer-facing address based on {@see $customer_address_type}.
     * For direct-customer orders, resolves from {@see $customer}.
     * For dealer-only orders (no {@see $customer_id}), resolves from {@see $billingCustomer}.
     * When set to {@see CustomerAddressType::Shipping}, returns the shipping address with a fallback to billing.
     * When set to {@see CustomerAddressType::Billing} (default), returns the billing address, with
     * fallback to {@see Customer::$address} for B2C customers created without a separate billing address.
     */
    public function getCustomerAddress(): ?Address
    {
        $this->loadMissing(['customer.billingAddress', 'customer.shippingAddress', 'customer.address']);

        $customer = $this->customer;

        if ($customer === null) {
            return null;
        }

        if ($this->getCustomerAddressType() === CustomerAddressType::Shipping) {
            return $customer->shippingAddress
                ?? $customer->billingAddress
                ?? $customer->address;
        }

        return $customer->billingAddress ?? $customer->address;
    }


    /**
     * Billing address type key derived from {@see $billing_customer_id} vs {@see $customer_id}.
     */
    public function resolveBillingAddressTypeKey(): string
    {
        if ($this->billing_customer_id !== null) {
            return (int)$this->billing_customer_id === (int)$this->customer_id
                ? 'customer'
                : 'customer-' . $this->billing_customer_id;
        }

        return 'customer';
    }

    /**
     * Billing party as {@see AddressType} (customer / dealer / custom address).
     */
    public function getBillingAddressType(): AddressType
    {
        $key = $this->resolveBillingAddressTypeKey();

        if ($key === 'custom') {
            return AddressType::Custom;
        }

        if ($key === 'customer' || $key === 'rd') {
            return AddressType::Customer;
        }

        if (str_starts_with($key, 'customer-') || str_starts_with($key, 'company-')) {
            $id = (int)preg_replace('#^(customer|company)-#', '', $key);

            return $id === (int)$this->customer_id
                ? AddressType::Customer
                : AddressType::Company;
        }

        if ($this->billing_customer_id !== null && (int)$this->billing_customer_id !== (int)$this->customer_id) {
            return AddressType::Company;
        }

        return AddressType::Customer;
    }

    /**
     * Physical delivery address for the chosen shipping customer ({@see Customer::getPhysicalDeliveryAddress()}),
     * not only the separate shippingAddress record.
     */
    public function getShippingAddress(): ?Address
    {
        $customer = $this->shippingCustomer;
        if ($customer === null && $this->billing_customer_id !== null) {
            $this->loadMissing('billingCustomer');
            $customer = $this->billingCustomer;
        }
        if ($customer === null && $this->customer_id !== null) {
            $this->loadMissing('customer');
            $customer = $this->customer;
        }
        if ($customer === null) {
            return null;
        }

        return $customer->getPhysicalDeliveryAddress();
    }

    /**
     * Type key for delivery address (customer, customer-{id}, rd, custom, …), same as Filament {@see \App\Filament\Resources\OrderResource\Pages\EditOrder::getInitialShippingAddressTypeKey()}.
     */
    public function resolveShippingAddressTypeKey(): string
    {
        if ($this->shipping_customer_id !== null) {
            return (int)$this->shipping_customer_id === (int)$this->customer_id
                ? 'customer'
                : 'customer-' . $this->shipping_customer_id;
        }

        $additional = $this->getAdditional() ?? [];
        $key = $additional['shipping_address_type_key'] ?? null;
        if (is_string($key) && $key !== '') {
            return $key;
        }

        if ($this->main_id !== null) {
            $this->loadMissing('main');
            $mainAdditional = $this->main?->getAdditional() ?? [];
            $key = $mainAdditional['shipping_address_type_key'] ?? null;
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return 'customer';
    }

    /**
     * String key gebruikt door o.a. klant-leveringsmails ({@see \App\Mail\Unit\DeliveryConfirmationMailCustomer}).
     */
    public function getShippingAddressType(): string
    {
        return $this->resolveShippingAddressTypeKey();
    }

    private function resolveShippingAddressAttribute(): ?Address
    {
        if ($this->resolveShippingAddressTypeKey() === 'custom') {
            return $this->buildVirtualCustomShippingAddressFromAdditional();
        }

        return $this->getShippingAddress();
    }

    /**
     * Onopgeslagen {@see Address} uit `additional.delivery_address` / `shipping_name` (type custom).
     */
    private function buildVirtualCustomShippingAddressFromAdditional(): ?Address
    {
        $additional = $this->getAdditional() ?? [];
        $delivery = $additional['delivery_address'] ?? null;
        if (!is_array($delivery)) {
            return null;
        }

        $street = trim((string)($delivery['street'] ?? ''));
        $city = trim((string)($delivery['city'] ?? ''));
        if ($street === '' && $city === '') {
            return null;
        }

        $shippingName = trim((string)($additional['shipping_name'] ?? ''));
        $emailRaw = $delivery['email'] ?? data_get($delivery, 'additional.email');
        $email = is_string($emailRaw) && trim($emailRaw) !== '' ? trim($emailRaw) : null;

        $address = new Address();
        $address->name = $shippingName !== '' ? $shippingName : null;
        $address->email = $email;
        $address->street = $delivery['street'] ?? null;
        $address->house_number = $delivery['house_number'] ?? null;
        $address->house_number_addition = $delivery['house_number_addition'] ?? null;
        $address->postcode = $delivery['postcode'] ?? null;
        $address->city = $delivery['city'] ?? null;
        $address->country_id = isset($delivery['country_id']) ? (int)$delivery['country_id'] : null;
        $address->region_id = isset($delivery['region_id']) ? (int)$delivery['region_id'] : null;
        $address->comment = isset($delivery['comment']) && is_string($delivery['comment']) ? $delivery['comment'] : null;
        $deliveryAdditional = $delivery['additional'] ?? null;
        $address->additional = is_array($deliveryAdditional) ? $deliveryAdditional : null;

        return $address;
    }

    public function getCreditInvoice(): ?CreditInvoice
    {
        return $this->creditInvoice;
    }

    public function setCreditInvoice(?CreditInvoice $creditInvoice): void
    {
        $this->creditInvoice = $creditInvoice;
    }

    public function getCustomOrderProducts(): Collection
    {
        return $this->customOrderProducts;
    }

    public function setCustomOrderProducts(Collection $customOrderProducts): void
    {
        $this->customOrderProducts = $customOrderProducts;
    }

    public function getCustomOrderProductsCount(): ?int
    {
        return $this->custom_order_products_count;
    }

    public function setCustomOrderProductsCount(?int $custom_order_products_count): void
    {
        $this->custom_order_products_count = $custom_order_products_count;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function getDeliveryAdvisor(): ?User
    {
        return $this->deliveryAdvisor;
    }

    public function setDeliveryAdvisor(?User $deliveryAdvisor): void
    {
        $this->deliveryAdvisor = $deliveryAdvisor;
    }

    public function getDepositInvoice(): ?DepositInvoice
    {
        return $this->depositInvoice;
    }

    public function setDepositInvoice(?DepositInvoice $depositInvoice): void
    {
        $this->depositInvoice = $depositInvoice;
    }

    public function getCreatedAtShort(): string
    {
        return $this->created_at_short;
    }

    public function setCreatedAtShort(string $created_at_short): void
    {
        $this->created_at_short = $created_at_short;
    }

    public function getLatestExpectedDeliveryDate(): mixed
    {
        return $this->latest_expected_delivery_date;
    }

    public function setLatestExpectedDeliveryDate(mixed $latest_expected_delivery_date): void
    {
        $this->latest_expected_delivery_date = $latest_expected_delivery_date;
    }

    public function getSpMarginSummary(): string
    {
        return $this->sp_margin_summary;
    }

    public function setSpMarginSummary(string $sp_margin_summary): void
    {
        $this->sp_margin_summary = $sp_margin_summary;
    }

    public function getTypeTranslated(): string
    {
        return $this->type_translated;
    }

    public function setTypeTranslated(string $type_translated): void
    {
        $this->type_translated = $type_translated;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function getMtoOrderProducts(): Collection
    {
        return $this->mtoOrderProducts;
    }

    public function setMtoOrderProducts(Collection $mtoOrderProducts): void
    {
        $this->mtoOrderProducts = $mtoOrderProducts;
    }

    public function getMtoOrderProductsCount(): ?int
    {
        return $this->mto_order_products_count;
    }

    public function setMtoOrderProductsCount(?int $mto_order_products_count): void
    {
        $this->mto_order_products_count = $mto_order_products_count;
    }

    public function getMtsOrderProducts(): Collection
    {
        return $this->mtsOrderProducts;
    }

    public function setMtsOrderProducts(Collection $mtsOrderProducts): void
    {
        $this->mtsOrderProducts = $mtsOrderProducts;
    }

    public function getMtsOrderProductsCount(): ?int
    {
        return $this->mts_order_products_count;
    }

    public function setMtsOrderProductsCount(?int $mts_order_products_count): void
    {
        $this->mts_order_products_count = $mts_order_products_count;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): void
    {
        $this->order = $order;
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function setChildren(Collection $children): void
    {
        $this->children = $children;
    }

    public function getFittings(): Collection
    {
        return $this->fittings;
    }

    public function setFittings(Collection $fittings): void
    {
        $this->fittings = $fittings;
    }

    public function getFrameProduct(): ?Product
    {
        return $this->frameProduct;
    }

    public function setFrameProduct(?Product $frameProduct): void
    {
        $this->frameProduct = $frameProduct;
    }

    public function getOrderProducts(): Collection
    {
        return $this->orderProducts;
    }

    public function setOrderProducts(Collection $orderProducts): void
    {
        $this->orderProducts = $orderProducts;
    }

    public function getOrderProductsCount(): ?int
    {
        return $this->order_products_count;
    }

    public function setOrderProductsCount(?int $order_products_count): void
    {
        $this->order_products_count = $order_products_count;
    }

    public function getOrderProductsWithoutCustomProductsCount(): ?int
    {
        return $this->order_products_without_custom_products_count;
    }

    public function setOrderProductsWithoutCustomProductsCount(?int $order_products_without_custom_products_count): void
    {
        $this->order_products_without_custom_products_count = $order_products_without_custom_products_count;
    }

    public function getStatusChanges(): Collection
    {
        return $this->statusChanges;
    }

    public function setStatusChanges(Collection $statusChanges): void
    {
        $this->statusChanges = $statusChanges;
    }

    public function getStatusChangesCount(): ?int
    {
        return $this->status_changes_count;
    }

    public function setStatusChangesCount(?int $status_changes_count): void
    {
        $this->status_changes_count = $status_changes_count;
    }

}

<?php

namespace App\Models\Order;

use App\Casts\PurchaseOrderStatusCast;
use App\Enums\FulfillmentType;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderProductStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Exceptions\OrderMissingDataException;
use App\Exceptions\OrderNotSavedException;
use App\Models\Concerns\FormatsDeliveryAddressLine;
use App\Models\Customer;
use App\Models\OrderProduct;
use App\Models\PurchaseOrder;
use App\Observers\StockOrderObserver;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Exception;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property int $id
 * @property string|null $type
 * @property PurchaseOrderStatus|null $status
 * @property OrderStatus|null $order_status
 * @property string|null $uid
 * @property int $rev
 * @property int $is_test
 * @property string $company_purchase_price_base Inkoop | Basisprijs
 * @property string $company_purchase_price_discount Inkoop | Inkoopkorting
 * @property string $company_purchase_price_total Inkoop | Totaal
 * @property string $company_sales_price_base Verkoop | Basisprijs
 * @property string $company_sales_price_discount Verkoop | Inkoopkorting
 * @property string $company_sales_price_total Verkoop | Totaal
 * @property string|null $payment_percentage
 * @property string|null $payment_amount
 * @property string $deposit_amount
 * @property string|null $reference
 * @property int $is_verified
 * @property int $is_force_verified
 * @property string|null $discount_comment
 * @property string|null $order_comment
 * @property int|null $delivery_week
 * @property string|null $session_id
 * @property int|null $showroom_id
 * @property int|null $supplier_id
 * @property int|null $order_id
 * @property int|null $quote_id
 * @property int|null $quote_company_id
 * @property int|null $order_company_id
 * @property int|null $invoice_id
 * @property int|null $credit_invoice_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $expires_at
 * @property string|null $public_access_token
 * @property string|null $exact_id
 * @property Carbon|null $exact_synced_at
 * @property Carbon|null $exact_error_at
 * @property array<array-key, mixed>|null $additional
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $payment_id
 * @property int|null $deposit_invoice_id
 * @property string|null $doc
 * @property string|null $doc_id
 * @property string|null $doc_path
 * @property string|null $note_company_internal
 * @property string|null $note_customer_internal
 * @property int|null $dealer_invoice
 * @property Carbon|null $cancelled_at
 * @property int|null $is_cancelled
 * @property string|null $cancel_comment
 * @property int $is_cancellation_credit_invoice
 * @property-read \App\Models\Order\CreditInvoice|null $creditInvoice
 * @property-read Collection<int, OrderProduct> $customOrderProducts
 * @property-read int|null $custom_order_products_count
 * @property-read Customer|null $customer
 * @property-read \App\Models\Order\DepositInvoice|null $depositInvoice
 * @property-read string $created_at_short
 * @property-read mixed $latest_expected_delivery_date
 * @property-read string $sp_margin_summary
 * @property-read string $type_translated
 * @property-read string $uid_formatted
 * @property-read \App\Models\Order\Invoice|null $invoice
 * @property-read Collection<int, OrderProduct> $mtoOrderProducts
 * @property-read int|null $mto_order_products_count
 * @property-read Collection<int, OrderProduct> $mtsOrderProducts
 * @property-read int|null $mts_order_products_count
 * @property-read \App\Models\Order\Order|null $order
 * @property-read Collection<int, OrderProduct> $orderProducts
 * @property-read int|null $order_products_count
 * @property-read int|null $order_products_without_custom_products_count
 * @property-read Collection<int, PurchaseOrder> $purchaseOrders
 * @property-read int|null $purchase_orders_count
 * @property-read \App\Models\Order\Quote|null $quote
 * @property-read \App\Models\Order\Quote|null $quoteCompany
 * @property-read int|null $status_changes_count
 * @method static Builder<static>|BaseOrder newModelQuery()
 * @method static Builder<static>|BaseOrder newQuery()
 * @method static Builder<static>|BaseOrder query()
 * @method static Builder<static>|BaseOrder status(string $status)
 * @method static Builder<static>|BaseOrder type(string $type)
 * @method static Builder<static>|BaseOrder whereAdditional($value)
 * @method static Builder<static>|BaseOrder whereCancelledAt($value)
 * @method static Builder<static>|BaseOrder whereCancelComment($value)
 * @method static Builder<static>|BaseOrder whereCompanyPurchasePriceBase($value)
 * @method static Builder<static>|BaseOrder whereCompanyPurchasePriceDiscount($value)
 * @method static Builder<static>|BaseOrder whereCompanyPurchasePriceTotal($value)
 * @method static Builder<static>|BaseOrder whereCompanySalesPriceBase($value)
 * @method static Builder<static>|BaseOrder whereCompanySalesPriceDiscount($value)
 * @method static Builder<static>|BaseOrder whereCompanySalesPriceTotal($value)
 * @method static Builder<static>|BaseOrder whereCreatedAt($value)
 * @method static Builder<static>|BaseOrder whereCreditInvoiceId($value)
 * @method static Builder<static>|BaseOrder whereDealerInvoice($value)
 * @method static Builder<static>|BaseOrder whereDeliveryWeek($value)
 * @method static Builder<static>|BaseOrder whereDepositAmount($value)
 * @method static Builder<static>|BaseOrder whereDepositInvoiceId($value)
 * @method static Builder<static>|BaseOrder whereDiscountComment($value)
 * @method static Builder<static>|BaseOrder whereDoc($value)
 * @method static Builder<static>|BaseOrder whereDocId($value)
 * @method static Builder<static>|BaseOrder whereDocPath($value)
 * @method static Builder<static>|BaseOrder whereDraftType($value)
 * @method static Builder<static>|BaseOrder whereExactErrorAt($value)
 * @method static Builder<static>|BaseOrder whereExactId($value)
 * @method static Builder<static>|BaseOrder whereExactSyncedAt($value)
 * @method static Builder<static>|BaseOrder whereExpiresAt($value)
 * @method static Builder<static>|BaseOrder whereId($value)
 * @method static Builder<static>|BaseOrder whereInvoiceId($value)
 * @method static Builder<static>|BaseOrder whereIsAdminGenerated($value)
 * @method static Builder<static>|BaseOrder whereIsCancellationCreditInvoice($value)
 * @method static Builder<static>|BaseOrder whereIsCancelled($value)
 * @method static Builder<static>|BaseOrder whereIsForceVerified($value)
 * @method static Builder<static>|BaseOrder whereIsTest($value)
 * @method static Builder<static>|BaseOrder whereIsVerified($value)
 * @method static Builder<static>|BaseOrder whereNoteCompanyInternal($value)
 * @method static Builder<static>|BaseOrder whereNoteCustomerInternal($value)
 * @method static Builder<static>|BaseOrder whereOrderComment($value)
 * @method static Builder<static>|BaseOrder whereOrderCompanyId($value)
 * @method static Builder<static>|BaseOrder whereOrderId($value)
 * @method static Builder<static>|BaseOrder whereOrderStatus($value)
 * @method static Builder<static>|BaseOrder wherePaymentAmount($value)
 * @method static Builder<static>|BaseOrder wherePaymentId($value)
 * @method static Builder<static>|BaseOrder wherePaymentPercentage($value)
 * @method static Builder<static>|BaseOrder wherePublicAccessToken($value)
 * @method static Builder<static>|BaseOrder whereQuoteId($value)
 * @method static Builder<static>|BaseOrder whereQuoteCompanyId($value)
 * @method static Builder<static>|BaseOrder whereReference($value)
 * @method static Builder<static>|BaseOrder whereRev($value)
 * @method static Builder<static>|BaseOrder whereSentAt($value)
 * @method static Builder<static>|BaseOrder whereSessionId($value)
 * @method static Builder<static>|BaseOrder whereShowroomId($value)
 * @method static Builder<static>|BaseOrder whereStatus($value)
 * @method static Builder<static>|BaseOrder whereSubsiteDirectQuote($value)
 * @method static Builder<static>|BaseOrder whereSubsiteId($value)
 * @method static Builder<static>|BaseOrder whereSubsiteOrder($value)
 * @method static Builder<static>|BaseOrder whereSupplierId($value)
 * @method static Builder<static>|BaseOrder whereType($value)
 * @method static Builder<static>|BaseOrder whereUid($value)
 * @method static Builder<static>|BaseOrder whereUpdatedAt($value)
 * @mixin Eloquent
 */
#[ObservedBy([StockOrderObserver::class])]
class StockOrder extends BaseOrder
{
    use FormatsDeliveryAddressLine;

    protected $table = 'orders';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => PurchaseOrderStatusCast::class,
        ]);
    }

    public function getStatus(): ?PurchaseOrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderGeneralStatus|PurchaseOrderStatus|string $value): self
    {
        $this->status = $value;
        return $this;
    }

    /**
     * Generate next stock-order UID using BaseOrder rules (MTS-YYYY-####).
     *
     * @throws Exception
     */
    public function getNewUid(): ?string
    {
        return parent::getNewUid();
    }

    /**
     * Order lines for stock-order HTML/PDF: use rows linked via orders.order_id first; if none,
     * use order lines attached to the related purchase order(s) (purchase_order_id only).
     *
     * @return Collection<int, OrderProduct>
     */
    public function getDocumentOrderProducts(): Collection
    {
        $this->loadMissing([
            'orderProducts.product',
        ]);

        if ($this->orderProducts->isNotEmpty()) {
            return $this->orderProducts;
        }

        $this->loadMissing([
            'purchaseOrders.orderProducts.product',
        ]);

        $merged = new Collection;
        foreach ($this->purchaseOrders as $purchaseOrder) {
            $merged = $merged->merge($purchaseOrder->orderProducts);
        }

        return $merged->unique('id')->values();
    }

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('type',
            fn(Builder $builder) => $builder->where('type', 'stock_order'));
    }

    /**
     * @throws Throwable
     */
    public function submitStockOrder(): self
    {
        throw_if($this->getType() !== OrderType::StockOrder,
            new OrderMissingDataException(
                "Order type must be 'stock_order' (was '" . ($this->getType()?->value ?? '') . "')")
        );
        $submittedStatuses = [
            PurchaseOrderStatus::Purchased,
            PurchaseOrderStatus::Confirmed,
            PurchaseOrderStatus::PartiallyDelivered,
            PurchaseOrderStatus::Delivered,
            PurchaseOrderStatus::Cancelled,
        ];
        throw_if(in_array($this->getStatus(), $submittedStatuses, true),
            new OrderMissingDataException(
                "Stock order is already submitted")
        );

        DB::beginTransaction();
        try {
            throw_unless($this->save(),
                new OrderNotSavedException('Stock order could not be set to completed')
            );

            $this
                ->setSentAt(now())
                ->setRev(0)
                ->setExpiresAt(null)
                ->setIsVerified(true)
                ->setType('stock_order')
                ->setStatus(PurchaseOrderStatus::Purchased)
                ->setOrderStatus(OrderStatus::Order)
                ->setUid($this->getNewUid());

            $this->save();

            $this->createPurchaseOrder();

            $main = $this->order_id !== null
                ? Main::withoutGlobalScopes()->find($this->order_id)
                : null;
            if ($main instanceof Main && $main->getOrderStatus() === OrderStatus::OrderAwaitingPurchase) {
                $main->changeOrderStatus(OrderStatus::PartiallyPurchased);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        info('Stock order created with ID ' . $this->getId());

        return $this;
    }

    protected function createPurchaseOrder(): void
    {
        $supplierId = $this->getSupplierId();
        throw_if($supplierId === null,
            new OrderMissingDataException('Stock order must have a supplier to create a purchase order'));

        $refNumber = $this->getUidFormatted() ?? 'stock-' . $this->getId();
        if ($this->getIsTest()) {
            $refNumber = 'TEST-' . $refNumber;
        }

        $purchaseOrder = PurchaseOrder::query()
            ->where('reference_number', $refNumber)
            ->first();

        if ($purchaseOrder === null) {
            $purchaseOrder = (new PurchaseOrder())
                ->setReferenceNumber($refNumber)
                ->setOrderId($this->getId())
                ->setSupplierId($supplierId)
                ->setType(PurchaseOrderType::Stock)
                ->setStatus(PurchaseOrderStatus::Initial);
            $purchaseOrder->save();
        }

        /** @var iterable<OrderProduct> $lines */
        $lines = OrderProduct::query()
            ->where('order_id', $this->getId())
            ->get();

        foreach ($lines as $orderProduct) {
            $orderProduct->setPurchaseOrderId($purchaseOrder->getId());
            $orderProduct->setStatus(OrderProductStatus::Purchased);
            $orderProduct->setFulfillmentType(FulfillmentType::MakeToStock);
            $orderProduct->setPurchasedAt(now());
            $orderProduct->save();
        }
    }
}

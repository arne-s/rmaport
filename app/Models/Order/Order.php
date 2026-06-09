<?php

namespace App\Models\Order;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Enums\ProductType;
use App\Models\DeliveryNote;
use App\Models\PackingSlip;
use App\Exceptions\OrderMissingDataException;
use App\Exceptions\OrderNotDuplicatedException;
use App\Jobs\SyncInvoiceToExactJob;
use App\Observers\OrderObserver;
use App\Models\Document;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Throwable;

#[ObservedBy([OrderObserver::class])]
class Order extends BaseOrder
{
    protected $table = 'orders';

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('type', function (Builder $builder) {
            $fromTable = array_last(explode(' as ', $builder->getQuery()->from));
            return $builder->where($fromTable . '.type', 'order');
        });
        static::saving(function (self $order): void {
            if ($order->isDirty(['billing_customer_id', 'customer_id', 'subtype'])) {
                $order->applyMainDefaultsBillingTermsFromContext();
            }

            $order->removeLegacyOrderDateFromAdditional();
        });
    }

    /**
     * When used from widget approve action: no-op (order is already accepted).
     * When used from EditOrder action "placeOrder" finalizes this order (company order + reserve stock).
     *
     * @throws OrderNotDuplicatedException
     * @throws Throwable
     */
    public function acceptOrder(): self
    {
        if (BaseOrder::withoutGlobalScopes()->where('order_id', $this->getId())->exists()) {
            return $this;
        }

        throw_if($this->getType() !== OrderType::Order,
            new OrderMissingDataException(
                "Order type must be 'order' (was '{$this->getType()?->value}')")
        );

        $order = Order::withoutGlobalScopes()->where('order_id', $this->getId())->first();
        if ($order !== null) {
            $order->delete();
            $this->refresh();
        }

        $order = $this->duplicate(false)
            ->setRev(0)
            ->setExpiresAt(null)
            ->setType(OrderType::Order)
            ->setStatus(OrderGeneralStatus::Pending)
            ->setOrderStatus(OrderStatus::Order)
            ->setOrderId($this->getId());

        $order->save();


        $order?->main?->updateQuietly(['order_id' => $order->getId()]);
        $order?->main?->changeOrderStatus(OrderStatus::OrderAudit);

        $inventoryService = app(InventoryService::class);
        $inventoryService->reserveForOrder($this);

        return $this;
    }

    /**
     * @param  bool  $sendSlotInvoiceMailImmediately  When false, skip {@see sendInvoiceMail()}; use when the caller queues {@see SendInvoiceMailJob} with a delay (e.g. {@see Main::createInvoiceIfRequired()}).
     *
     * @throws OrderMissingDataException
     * @throws Throwable
     */
    public function createInvoice(bool $sendSlotInvoiceMailImmediately = true): Invoice
    {
        throw_if($this->getType()->value !== 'order',
            new OrderMissingDataException(
                "Order type must be 'order' (was '{$this->getType()->value}')")
        );

        throw_if($this->getStatus() === OrderGeneralStatus::Completed,
            new OrderMissingDataException(
                "Order is already completed")
        );

        $invoice = $this->duplicate(false)
            ->setSentAt(null)
            ->setExpiresAt(null)
            ->setType(OrderType::Invoice)
            ->setStatus(OrderGeneralStatus::Pending)
            ->setCompanySalesPriceDiscount(0)
            ->setOrderId($this->getId())
            ->setDiscountComment(null)
            ->setUid(null);

        $invoice->save();

        // Load as proper Invoice model
        $invoice = Invoice::where('id', $invoice->getId())->first();

        /* @var Invoice $invoice * */
        $invoice
            ->setUid($invoice->getNewUid())
            ->setInitialPaymentAmount();

        $invoice->save();

        Document::createFromOrder($invoice);

        $main = $this->main;
        if ($main instanceof Main && $main->getSubtype() === OrderSubtype::Service) {
            if ($this->getSentAt() !== null) {
                $this->setStatus(OrderGeneralStatus::Sent);
            }
        } else {
            $this->setStatus('completed');
        }

        $this->setInvoiceId($invoice->getId());
        $this->save();
        if ($main instanceof Main) {
            $uid = (string) ($invoice->getUid() ?? '');
            $slotTotal = $invoice->getCompanySalesPriceTotalIncVat();
            $outstandingInclVat = (float) ($invoice->getPaymentAmount() ?? 0.0);
            $main->orderEvents()->create([
                'type' => 'Slotfactuur is aangemaakt: '.$uid.$invoice->describeTotalsForOrderEvent($slotTotal, $outstandingInclVat),
                'data' => [],
                'user_id' => Auth::id(),
            ]);
        }

        info('Invoice created with ID ' . $invoice->getId() .
            ', from order with ID ' . $this->getId());

        $invoice->refresh();
        if ($sendSlotInvoiceMailImmediately) {
            $invoice->sendInvoiceMail();
        }

        if (config('exact.enabled')) {
            SyncInvoiceToExactJob::dispatch($invoice->getId(), Auth::id());
        }

        return $invoice;
    }

    public function needDepositInvoice(): bool
    {
        if ($this->payment_terms instanceof PaymentTerms) {
            return PaymentTerms::requiresDepositInvoice($this->payment_terms);
        }

        $resolved = PaymentTerms::tryFrom($this->getPaymentTermsValueForBillingContext());

        return PaymentTerms::requiresDepositInvoice($resolved);
    }


    public function createDepositInvoice(): ?DepositInvoice
    {
        $depositInvoice = $this->duplicate(false)
            ->setSentAt(null)
            ->setRev(0)
            ->setCompanySalesPriceDiscount(0)
            ->setExpiresAt(null)
            ->setType(OrderType::DepositInvoice)
            ->setStatus(OrderGeneralStatus::Pending)
            ->setDepositInvoiceId(null)
            ->setInvoiceId(null)
            ->setCreditInvoiceId(null)
            ->setOrderId($this->getId())
            ->setUid(null);

        if ($this->main_id !== null) {
            $depositInvoice->main_id = $this->main_id;
        }

        $depositInvoice->save();

        $depositInvoice = DepositInvoice::where('id', $depositInvoice->getId())->first();
        $depositInvoice->setUid($depositInvoice->getNewUid())
            ->setInitialDepositAmount();

        $depositInvoice->save();

        Document::createFromOrder($depositInvoice);

        $this->setDepositInvoiceId($depositInvoice->getId());
        $this->setIsVerified(false);
        $this->save();

        $main = $this->main;
        if ($main instanceof Main) {
            $main->setDepositInvoiceId($depositInvoice->getId());
            $main->saveQuietly();

            $uid = (string) ($depositInvoice->getUid() ?? '');
            $totalInclVat = $depositInvoice->getCompanySalesPriceTotalIncVat();
            $outstandingInclVat = (float) ($depositInvoice->getPaymentAmount() ?? 0.0);
            $main->orderEvents()->create([
                'type' => 'Aanbetalingsfactuur is aangemaakt: '.$uid.$depositInvoice->describeTotalsForOrderEvent($totalInclVat, $outstandingInclVat),
                'data' => [],
                'user_id' => Auth::id(),
            ]);
        }

        $depositInvoice->refresh();

        if (config('exact.enabled')) {
            SyncInvoiceToExactJob::dispatch($depositInvoice->getId(), Auth::id());
        }

        return $depositInvoice;
    }

    public function packingSlips(): HasMany
    {
        return $this->hasMany(PackingSlip::class, 'order_id');
    }

    public function deliveryNote(): HasOne
    {
        return $this->hasOne(DeliveryNote::class, 'order_id');
    }

    /**
     * Root order lines that may appear on a packing slip / afleverbon.
     *
     * Non-service aanvragen: exclude service-type articles (e.g. labour lines). Service aanvragen often only
     * have service-type products; those must still be eligible so the afleverbon flow can run at assembly.
     */
    public function packingSlipEligibleOrderProducts(): HasMany
    {
        return $this->orderProducts()
            ->where(function (Builder $q): void {
                $q->whereHas('order.main', function (Builder $m): void {
                    $m->where('subtype', OrderSubtype::Service->value);
                })->orWhere(function (Builder $line): void {
                    $line->where('order_products.type', '!=', ProductType::Service->value)
                        ->orWhereNull('order_products.type');
                });
            });
    }

    public function getOrderDate(): Carbon
    {
        return ($this->created_at ?? now())->copy()->startOfDay();
    }

    /**
     * Set the business order date on {@see $created_at} (calendar date only; time-of-day is preserved).
     */
    public function syncCreatedAtFromOrderDate(Carbon|string|null $orderDate = null): bool
    {
        $date = $orderDate !== null
            ? Carbon::parse($orderDate)->startOfDay()
            : $this->getOrderDate();

        $existing = $this->created_at ?? now();
        $synced = $date->copy()->setTime(
            (int) $existing->format('H'),
            (int) $existing->format('i'),
            (int) $existing->format('s'),
        );

        if ($this->created_at !== null && $this->created_at->equalTo($synced)) {
            return false;
        }

        $this->created_at = $synced;

        return true;
    }

    public function restrictsOrderDateToCreatedAt(): bool
    {
        return $this->getQuoteId() !== null;
    }

    public function resetOrderDateToToday(): self
    {
        $this->syncCreatedAtFromOrderDate(now());

        return $this->removeLegacyOrderDateFromAdditional();
    }

    public function removeLegacyOrderDateFromAdditional(): self
    {
        $additional = $this->getAdditional();

        if ($additional === null || ! array_key_exists('order_date', $additional)) {
            return $this;
        }

        unset($additional['order_date']);

        return $this->setAdditional($additional !== [] ? $additional : null);
    }

    /**
     * Create a new revision of this order for editing. The new order gets status Initial
     * and rev 0. Other orders keep their status. On first save, the new order receives
     * the proper rev and status Pending, other orders are marked Changed, and inventory
     * is released/reserved.
     */
    public function createNewRevision(): self
    {
        return DB::transaction(function (): self {
            if ($this->main_id !== null) {
                Main::query()->whereKey($this->main_id)->lockForUpdate()->first();
            }

            $this->refresh();
            $this->load('orderProducts');

            $newOrder = $this->duplicate();
            $newOrder->setRev(0);
            $newOrder->setStatus(OrderGeneralStatus::Initial);
            $newOrder->resetOrderDateToToday();
            $newOrder->save();

            $newOrder->main?->updateQuietly(['order_id' => $newOrder->getId()]);

            return $newOrder;
        });
    }

    /**
     * @throws \RuntimeException
     */
    public static function resolveForEmailPreview(?OrderSubtype $subtype = null): self
    {
        $applySubtype = function (Builder $query) use ($subtype): Builder {
            if ($subtype !== null) {
                $query->whereHas('main', fn (Builder $mainQuery): Builder => $mainQuery->where('subtype', $subtype->value));
            }

            return $query;
        };

        $order = $applySubtype(static::query())
            ->whereNotNull('uid')
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('customer_id')
                    ->orWhereNotNull('billing_customer_id');
            })
            ->with(['customer', 'billingCustomer', 'main'])
            ->whereHas('main')
            ->latest()
            ->first();

        if ($order === null) {
            $order = $applySubtype(static::query())
                ->whereNotNull('uid')
                ->with(['customer', 'billingCustomer', 'main'])
                ->whereHas('main')
                ->latest()
                ->first();
        }

        if ($order === null) {
            $order = $applySubtype(static::query())
                ->with(['customer', 'billingCustomer', 'main'])
                ->whereHas('main')
                ->latest()
                ->first();
        }

        if ($order === null) {
            throw new \RuntimeException('No order found for email preview.');
        }

        $order->getOrCreatePublicDownloadUuid();

        return $order;
    }

}

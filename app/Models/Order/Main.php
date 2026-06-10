<?php

namespace App\Models\Order;

use App\Jobs\SendDepositInvoiceMailJob;
use App\Jobs\SendInvoiceMailJob;
use App\Actions\SendOrderCheckAdministrationMailAction;
use App\Actions\SyncDeliveryNotePdfAction;
use App\Actions\SendOrderCheckAdvisorMailAction;
use App\Actions\SendOrderConfirmMailAction;
use App\Actions\SendAssemblyCompletedMailAction;
use App\Actions\SendAssemblyStartMailAction;
use App\Actions\SendPlanAssemblyMailAction;
use App\Actions\SendOrderReadyForQuoteMailAction;
use App\Enums\CustomerType;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderProductStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\OrderStatusChange;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Log;

class Main extends BaseOrder
{
    protected $table = 'orders';

    /**
     * All quotes linked to this main (request), including expired and other revisions.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'main_id', 'id')->orderByDesc('rev');
    }

    public function getDescriptor(): string
    {
        $name = $this->getCustomerAddressDisplayName();

        $base = $this->getUid() . ' (' . ($this->getSubtype()->getLabel() ?? '') . ') - ' . $name;
        $internal = trim((string) ($this->reference_internal ?? ''));

        if ($internal !== '') {
            return $base . ' - ' . $internal;
        }

        return $base;
    }

    /**
     * Payment terms copied onto new quote/order rows for this main.
     */
    public function getPaymentTermsInheritedByChildren(): PaymentTerms
    {
        if ($this->payment_terms instanceof PaymentTerms) {
            return $this->payment_terms;
        }

        return PaymentTerms::tryFrom($this->getPaymentTermsValueForBillingContext()) ?? PaymentTerms::Postpay;
    }

    /**
     * Exact payment condition code for `additional.exact_payment_condition` on child quote/order rows.
     */
    public function getExactPaymentConditionInheritedByChildren(): string
    {
        $mainAdditional = $this->getAdditional() ?? [];
        $code = $mainAdditional['exact_payment_condition'] ?? null;
        if (is_string($code) && $code !== '') {
            return $code;
        }

        return $this->getExactPaymentConditionCodeForView();
    }

    /**
     * Apply payment_terms and exact_payment_condition from a linked quote or order onto this main (caller saves).
     */
    public function applyBillingTermsFromSiblingDocument(BaseOrder $source): void
    {
        $terms = $source->payment_terms;
        if ($terms instanceof PaymentTerms) {
            $this->payment_terms = $terms;
        }

        $target = $this->getAdditional() ?? [];
        $src = $source->getAdditional() ?? [];

        if (array_key_exists('exact_payment_condition', $src)) {
            $condition = $src['exact_payment_condition'];
            if (is_string($condition) && $condition !== '') {
                $target['exact_payment_condition'] = $condition;
            } else {
                unset($target['exact_payment_condition']);
            }
        }

        $this->setAdditional($target);
    }

    /**
     * Party to use for salutation / contact fields.
     * When 'dealer' is requested, returns the billing customer.
     * Otherwise returns the end customer or invoice customer as fallback.
     */
    public function getVirtualCustomer(?string $primaryRecipientKey = null): Customer|null
    {
        return match ($primaryRecipientKey) {
            'dealer' => $this->billingCustomer,
            default  => $this->customer ?? $this->billingCustomer,
        };
    }

    /**
     * The quote used for finance display (purchase/sales/margin). First by rev.
     */
    public function getQuoteAttribute(): ?Quote
    {
        return $this->quotes->first();
    }

    /**
     * Latest quote, used as source for products to purchase
     */
    public function getNewestApprovedQuote(): ?Quote
    {
        return $this->quotes()
            ->where('status', OrderGeneralStatus::Completed)
            ->first();
    }

    /**
     * Latest quote for this main in draft general status, if any (highest id; {@see quotes()} default rev ordering is cleared).
     */
    public function draftQuote(): ?Quote
    {
        return $this->quotes()
            ->where('status', OrderGeneralStatus::Draft)
            ->reorder()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Latest sales order for this main in draft general status, if any (highest id).
     */
    public function draftOrder(): ?Order
    {
        return $this->orders()
            ->where('status', OrderGeneralStatus::Draft)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * True when this main already has a non-initial quote, a linked sales order id, or any non-initial order row.
     */
    public function hasQuoteOrOrderBeyondInitial(): bool
    {
        return $this->quotes()->where('status', '!=', OrderGeneralStatus::Initial)->exists()
            || $this->getOrderId() !== null
            || $this->orders()->where('status', '!=', OrderGeneralStatus::Initial)->exists();
    }

    /**
     * Whether a quote linked to this main was sent to the customer ({@see Quote::$sent_at}).
     */
    public function hasSentQuote(): bool
    {
        return $this->quotes()
            ->whereNotNull('sent_at')
            ->exists();
    }

    /**
     * Whether the passing appointment may still be edited or cancelled.
     */
    public function canModifyFittingAppointment(): bool
    {
        return ! $this->hasSentQuote();
    }

    /**
     * Whether the financial-docs Order shortcut should be hidden ({@see \App\Filament\Resources\OrderResource\Widgets\OrderDocsTableWidget::canShowCreateOrderFromRequestButton()}).
     * Service: allow an order draft alongside a draft or sent quote; block when the main has a linked order row, an accepted (completed) quote, or a sales order beyond draft.
     */
    public function shouldBlockDirectOrderCreationFromMainHeader(): bool
    {
        if ($this->getSubtype() !== OrderSubtype::Service) {
            return $this->hasQuoteOrOrderBeyondInitial();
        }

        if ($this->getOrderId() !== null) {
            return true;
        }

        if ($this->quotes()->where('status', OrderGeneralStatus::Completed)->exists()) {
            return true;
        }

        $sandboxOrderStatuses = [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value];

        return $this->orders()->whereNotIn('status', $sandboxOrderStatuses)->exists();
    }

    public function registerMediaCollections(): void
    {
        parent::registerMediaCollections();
        $this->addMediaCollection('fitting_documents');
        $this->addMediaCollection('product_documents');
        $this->addMediaCollection('financial_documents');
        $this->addMediaCollection('assembly_documents');
        $this->addMediaCollection('delivery_documents');
        $this->addMediaCollection('service_documents');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('type', fn(Builder $builder) => $builder->where($builder->getModel()->getTable() . '.type', OrderType::Main->value));
        static::saving(function (self $main): void {
            if ($main->isDirty(['billing_customer_id', 'customer_id', 'subtype'])) {
                $main->applyMainDefaultsBillingTermsFromContext();
            }
        });
        static::saved(function (self $main): void {
            $main->recalculateProductSummary();
            $main->syncCustomerDobFromFittingBirthDateIfEmpty();
        });
    }

    /**
     * When a birth date is stored in the fitting note, copy it to the linked customer if the customer has no date of birth yet.
     */
    public function syncCustomerDobFromFittingBirthDateIfEmpty(): void
    {
        if ($this->customer_id === null) {
            return;
        }

        $birthDateRaw = data_get($this->getFittingNote() ?? [], 'birth_date');
        if (!is_string($birthDateRaw) || trim($birthDateRaw) === '') {
            return;
        }

        $customer = Customer::query()->find($this->customer_id);
        if ($customer === null || $customer->dob !== null) {
            return;
        }

        try {
            $parsed = Carbon::parse(trim($birthDateRaw))->startOfDay();
        } catch (\Throwable) {
            return;
        }

        $customer->dob = $parsed->toDateString();
        $customer->saveQuietly();
    }

    public function buildProductSummary(): string
    {
        return '0 / 0 / 0 / 0';
    }

    public function recalculateProductSummary(): void
    {
        $summary = $this->buildProductSummary();
        if ($this->product_summary === $summary) {
            return;
        }

        $this->forceFill([
            'product_summary' => $summary,
        ])->saveQuietly();

        $this->refresh();
    }

    public function changeOrderStatus(OrderStatus $to): void
    {
        $from = $this->getOrderStatus();
        if ($from === $to) {
            return;
        }

        if ($to === OrderStatus::OrderAudit && $from !== OrderStatus::OrderDraft) {
            return;
        }


        if ($to === OrderStatus::Assembled) {
            if ($this->getSubtype() === OrderSubtype::Unit && ! $this->checklistHasCompletedEindcontrole()) {
                throw ValidationException::withMessages([
                    'order_status' => ['Checklist is niet afgerond.'],
                ]);
            }

            if ($this->getSubtype() !== OrderSubtype::Service) {
                $serial = trim((string) ($this->getSerialNumber() ?? ''));
                if ($serial === '') {
                    throw ValidationException::withMessages([
                        'order_status' => [
                            __('Het serienummer is niet ingevuld.'),
                        ],
                    ]);
                }
            }
        }

        if ($to === OrderStatus::Delivered
            && $this->getSubtype() === OrderSubtype::Part
            && ! $this->latestSalesOrderHasOrderConfirmationSent()) {
            throw ValidationException::withMessages([
                'order_status' => [
                    'De status Geleverd is pas mogelijk nadat de orderbevestiging van de laatste order is verzonden.',
                ],
            ]);
        }

        $completionStatus = OrderStatus::getFlowCompletionStatus($this->getSubtype());
        $isCompleted = $to === $completionStatus;

        $this->updateQuietly([
            'order_status' => $to->value,
            'is_completed' => $isCompleted,
        ]);

        OrderStatusChange::create([
            'order_id' => $this->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => Auth::id(),
            'meta' => null,
        ]);

        $oldLabel = $from !== null ? OrderStatus::formatForDisplay($from, false) : '-';
        $newLabel = OrderStatus::formatForDisplay($to, false);

        $this->orderEvents()->create([
            'type' => "Orderstatus gewijzigd: {$oldLabel} -> {$newLabel}",
            'data' => [
                'old_status' => $from?->value,
                'new_status' => $to->value,
            ],
            'user_id' => Auth::id(),
        ]);

        $this->runOrderStatusTasks($from, $to);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'main_id', 'id');
    }

    /**
     * QR pakbon PDF on the main ({@see SyncDeliveryNotePdfAction}, delivery_documents media collection), no e-mail.
     * Triggered on ReadyForPickup / DeliveryPlanned (unit and others) and on Received for service mains.
     * Checklist + signature afleverbon: {@see \App\Actions\CreatePackingSlipPdfAction}.
     */
    private function syncDeliveryNotePdfToMainDocuments(): void
    {
        $order = $this->resolveOrderForDeliveryNote();
        if ($order === null) {
            return;
        }

        try {
            app(SyncDeliveryNotePdfAction::class)->execute($order);
        } catch (\Throwable $e) {
            Log::error('SyncDeliveryNotePdfAction for QR delivery-note (delivery) failed', [
                'main_id' => $this->getKey(),
                'order_id' => $order->getKey(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @throws \Throwable
     */
    public function runOrderStatusTasks(?OrderStatus $from, OrderStatus $to): void
    {
        switch ($to) {

            case OrderStatus::OrderDraft:
                if (! $this->usesUnitSimplifiedSalesFlow()) {
                    app(SendOrderCheckAdministrationMailAction::class)->execute($this);
                }

                break;

            case OrderStatus::OrderSent:
                if ($this->usesUnitSimplifiedSalesFlow()) {
                    $this->runDepositInvoiceMailTasksIfNeededFromLatestOrder();
                    $this->createUnitB2bInvoiceAfterOrderConfirmationIfNeeded();
                }
                if ($this->getSubtype() === OrderSubtype::Part) {
                    $this->loadMissing(['billingCustomer']);
                    if ($this->billingCustomer?->getType() === CustomerType::B2C) {
                        try {
                            $this->createInvoiceIfRequired();
                        } catch (\Throwable $e) {
                            Log::error('Part B2C: create invoice after order sent failed', [
                                'main_id' => $this->getKey(),
                                'exception' => $e,
                            ]);
                        }
                    }
                }

                $this->changeOrderStatus(OrderStatus::OrderAwaitingPurchase);
                break;
            case OrderStatus::OrderAudit:
                $advisor = $this->advisor;
                if ($advisor?->getEmail()) {
                    app(SendOrderCheckAdvisorMailAction::class)->execute($this);
                }

                break;


            case OrderStatus::OrderApproved:
                $this->runDepositInvoiceMailTasksIfNeededFromLatestOrder();

                $order = $this->orders()?->whereNot('status', 'initial')?->first();
                if ($order && $this->getSubtype() !== OrderSubtype::Service) {
                    try {
                        $order->updateQuietly(['sent_at' => now()]);
                        $order->refresh();
                        app(SendOrderConfirmMailAction::class)->execute($order);

                        $this->orderEvents()->create([
                            'type' => 'Orderbevestiging aangemaakt en verzonden: ' . $order->getUidFormatted(),
                            'data' => [],
                            'user_id' => Auth::id(),
                        ]);

                    } catch (\Exception $e) {
                        Log::error('Failed to send order confirmation email: ' . $e->getMessage());
                    }
                }

                $this->createUnitB2bInvoiceAfterOrderConfirmationIfNeeded();

                $this->changeOrderStatus(OrderStatus::OrderAwaitingPurchase);

                break;
            case OrderStatus::QuoteDraft:
                app(SendOrderReadyForQuoteMailAction::class)->execute($this);

                break;

            case OrderStatus::AssemblyPlanned:
                try {
                    app(SendPlanAssemblyMailAction::class)->execute($this);
                } catch (\Throwable $e) {
                    Log::error('Failed to send plan assembly mail: ' . $e->getMessage());
                }

                break;

            case OrderStatus::ReadyForAssembly:
                if ($this->getSubtype() !== OrderSubtype::Service
                    && ! ($this->getSubtype() === OrderSubtype::Unit
                        && $this->billingCustomer?->type === CustomerType::B2B)) {
                    try {
                        $this->createInvoiceIfRequired();
                    } catch (\Throwable $e) {
                        Log::error('Failed to create invoice: ' . $e->getMessage(), ['exception' => $e]);
                    }
                }

                if ($this->getSubtype() === OrderSubtype::Unit) {
                    try {
                        app(SendAssemblyStartMailAction::class)->execute($this);
                    } catch (\Throwable $e) {
                        Log::error('Failed to send assembly start mail: ' . $e->getMessage());
                    }
                }

                break;

            case OrderStatus::Assembled:
                try {
                    app(SendAssemblyCompletedMailAction::class)->execute($this);
                } catch (\Throwable $e) {
                    Log::error('Failed to send assembly completed mail: ' . $e->getMessage());
                }

                if ($this->getSubtype() === OrderSubtype::Service) {
                    $this->runServiceAssembledSalesTasks();
                }

                break;

            case OrderStatus::ReadyForPickup:
            case OrderStatus::DeliveryPlanned:
                if (! $this->usesUnitSimplifiedSalesFlow()) {
                    $this->syncDeliveryNotePdfToMainDocuments();
                }

                break;

            case OrderStatus::Delivered:
                if ($this->getSubtype() === OrderSubtype::Part) {
                    $this->loadMissing(['billingCustomer']);
                    $partBilling = $this->billingCustomer?->getType();
                    if ($partBilling === CustomerType::B2B) {
                        try {
                            $this->createInvoiceIfRequired();
                        } catch (\Throwable $e) {
                            Log::error('Part B2B/dealer: create invoice after delivered failed', [
                                'main_id' => $this->getKey(),
                                'exception' => $e,
                            ]);
                        }
                    }
                } elseif ($this->billingCustomer?->type === CustomerType::B2B
                    && $this->getSubtype() !== OrderSubtype::Service) {
                    try {
                        $this->createInvoiceIfRequired();
                    } catch (\Throwable $e) {
                        Log::error('Failed to queue slot invoice mail after delivered: ' . $e->getMessage(), ['exception' => $e]);
                    }
                }

                break;

            case OrderStatus::Received:
                if ($this->getSubtype() === OrderSubtype::Part) {
                    $this->changeOrderStatus(OrderStatus::ReadyForPickup);
                }

                if ($this->getSubtype() === OrderSubtype::Service) {
                    $this->syncDeliveryNotePdfToMainDocuments();
                }

                break;

            default:
                break;
        }
    }

    /**
     * Create final invoice when needed from {@see getLastOrder()} (highest rev, non-initial).
     * Amount due is set via {@see Invoice::setInitialPaymentAmount()}: total inc. VAT minus deposit when applicable.
     */
    public function createInvoiceIfRequired(): void
    {
        if ($this->shouldSuppressAutomaticInvoicing()) {
            return;
        }

        $lastOrder = $this->getLastOrder();
        if (! $lastOrder instanceof Order) {
            return;
        }

        if ($this->getInvoiceId() === null) {
            $invoice = $lastOrder->createInvoice(sendSlotInvoiceMailImmediately: false);
            $this->setInvoiceId($invoice->getId())->saveQuietly();
        } else {
            $invoice = $this->invoice;
        }

        try {
            $delaySeconds = $this->resolveInvoiceMailDelaySecondsForDispatch();

            SendInvoiceMailJob::dispatchDelayedForInvoice($invoice->getId(), $delaySeconds);
        } catch (\Throwable $e) {
            Log::error('Failed to queue slot invoice mail: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    public function shouldSuppressAutomaticInvoicing(): bool
    {
        return false;
    }

    /**
     * Service flow: order confirmation + queued slot invoice when assembly is completed.
     */
    private function runServiceAssembledSalesTasks(): void
    {
        $order = $this->getLastOrder();
        if (! $order instanceof Order) {
            return;
        }

        $confirmationSent = $order->getStatus() === OrderGeneralStatus::Sent && $order->getSentAt() !== null;

        if (! $confirmationSent) {
            try {
                $order->generateDoc();
                app(SendOrderConfirmMailAction::class)->execute($order);
                $order->updateQuietly([
                    'sent_at' => $order->getSentAt() ?? now(),
                    'status' => OrderGeneralStatus::Sent,
                ]);

                $this->orderEvents()->create([
                    'type' => 'Orderbevestiging aangemaakt en verzonden: ' . $order->getUidFormatted(),
                    'data' => [],
                    'user_id' => Auth::id(),
                ]);

                $confirmationSent = true;
            } catch (\Throwable $e) {
                Log::error('Service assembled: order confirmation failed', [
                    'main_id' => $this->getKey(),
                    'order_id' => $order->getKey(),
                    'exception' => $e,
                ]);
            }
        }

        if (! $confirmationSent) {
            return;
        }

        $this->alignServiceSalesOrderDocumentStatusAfterInvoice($order);

        try {
            $this->createInvoiceIfRequired();
            $this->alignServiceSalesOrderDocumentStatusAfterInvoice($order);
        } catch (\Throwable $e) {
            Log::error('Service assembled: create invoice failed', [
                'main_id' => $this->getKey(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * {@see Order::createInvoice()} sets the sales order to {@see OrderGeneralStatus::Completed} (“Akkoord”);
     * service orders should stay {@see OrderGeneralStatus::Sent} (“Verzonden”) after the slot invoice is created.
     */
    private function alignServiceSalesOrderDocumentStatusAfterInvoice(Order $order): void
    {
        $order->refresh();

        if ($order->getStatus() !== OrderGeneralStatus::Completed) {
            return;
        }

        $order->setStatus(OrderGeneralStatus::Sent);
        $order->saveQuietly();
    }

    /**
     * Queue delay for {@see SendInvoiceMailJob} from flow-spec + {@see Setting::get()} mail delay settings.
     * Part subtype: B2C {@see Setting::get('mail.part_b2c_mail_delay_seconds')},
     * B2B {@see Setting::get('mail.part_b2b_mail_delay_seconds')}, dealer {@see Setting::get('mail.part_dealer_mail_delay_seconds')}.
     * Service: B2C {@see Setting::get('mail.service_b2c_invoice_mail_delay_seconds')}, dealer {@see Setting::get('mail.service_dealer_invoice_mail_delay_seconds')}.
     */
    public function invoiceMailDelaySecondsForDispatch(): int
    {
        return $this->resolveInvoiceMailDelaySecondsForDispatch();
    }

    protected function resolveInvoiceMailDelaySecondsForDispatch(): int
    {
        $this->loadMissing(['billingCustomer']);
        $billingType = $this->billingCustomer?->getType();
        $subtype = $this->getSubtype();

        if ($subtype === OrderSubtype::Part && $billingType === CustomerType::B2B) {
            return max(0, (int) Setting::get('mail.part_b2b_mail_delay_seconds'));
        }

        if ($subtype === OrderSubtype::Service && $billingType === CustomerType::B2C) {
            return max(0, (int) Setting::get('mail.service_b2c_invoice_mail_delay_seconds'));
        }

        if ($subtype === OrderSubtype::Unit && $billingType === CustomerType::B2B) {
            return max(0, (int) Setting::get('mail.full_invoice_delay'));
        }

        if ($billingType === CustomerType::B2B) {
            if ($subtype === OrderSubtype::Service) {
                return max(0, (int) Setting::get('mail.service_dealer_invoice_mail_delay_seconds'));
            }

            return max(0, (int) Setting::get('mail.dealer_invoice_mail_delay_seconds'));
        }

        if ($subtype === OrderSubtype::Part && $billingType === CustomerType::B2C) {
            return max(0, (int) Setting::get('mail.part_b2c_mail_delay_seconds'));
        }

        return max(0, (int) Setting::get('mail.invoice_mail_delay_seconds'));
    }

    /**
     * Deposit invoice queue shared by {@see OrderStatus::OrderApproved} and simplified Unit sales flow {@see OrderStatus::OrderSent}.
     */
    private function runDepositInvoiceMailTasksIfNeededFromLatestOrder(): void
    {
        if ($this->shouldSuppressAutomaticInvoicing()) {
            return;
        }

        $latestForDeposit = $this->getLatestOrderForInvoicing();
        if ($latestForDeposit instanceof Order && $latestForDeposit->needDepositInvoice()) {
            if ($this->needDepositInvoice()) {
                $depositInvoice = $latestForDeposit->createDepositInvoice();
                if ($depositInvoice !== null) {
                    try {
                        SendDepositInvoiceMailJob::dispatchDelayedForOrder($latestForDeposit->getId());
                    } catch (\Throwable $e) {
                        Log::error('Failed to queue deposit invoice email: ' . $e->getMessage());
                    }
                }
            } else {
                $deposit = $this->depositInvoice
                    ?? DepositInvoice::query()
                        ->where('main_id', $this->getKey())
                        ->first();

                if ($deposit instanceof DepositInvoice && $deposit->getSentAt() === null) {
                    try {
                        SendDepositInvoiceMailJob::dispatchDelayedForOrder($latestForDeposit->getId());
                    } catch (\Throwable $e) {
                        Log::error('Failed to queue deposit invoice email: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private function createUnitB2bInvoiceAfterOrderConfirmationIfNeeded(): void
    {
        $this->loadMissing(['billingCustomer']);
        if (
            $this->getSubtype() === OrderSubtype::Unit
            && $this->billingCustomer?->getType() === CustomerType::B2B
            && $this->getInvoiceId() === null
        ) {
            try {
                $this->createInvoiceIfRequired();
            } catch (\Throwable $e) {
                Log::error('Unit B2B: create invoice after order confirmation failed', [
                    'main_id' => $this->getKey(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Invoice (billing) customer types for which Unit uses the simplified sales flow (UI tabs, status dropdown,
     * purchase shipping address, derived statuses). Dealer keeps the standard Unit flow (incl. Passing / Montage).
     *
     * Uses the {@see Main::billingCustomer()} relation (`billing_customer_id`).
     *
     * @return list<CustomerType>
     */
    public static function billingTypesForUnitSimplifiedSalesFlow(): array
    {
        return [CustomerType::B2B];
    }

    /**
     * Whether this Unit main follows the simplified flow (invoice customer B2B only; not standard B2C Unit nor Dealer).
     */
    public function usesUnitSimplifiedSalesFlow(): bool
    {
        $this->loadMissing(['billingCustomer']);
        $type = $this->billingCustomer?->getType();

        return $this->getSubtype() === OrderSubtype::Unit
            && $type !== null
            && in_array($type, self::billingTypesForUnitSimplifiedSalesFlow(), true);
    }

    public function getAdministrationCheck(): ?OrderStatusChange
    {
        return $this->statusChanges()
            ->with('changedBy')
            ->where('from_status', OrderStatus::OrderDraft->value)
            ->where('to_status', OrderStatus::OrderAudit->value)
            ->orderByDesc('created_at')
            ->first();
    }

    public function getAdvisorCheck(): ?OrderStatusChange
    {
        return $this->statusChanges()
            ->with('changedBy')
            ->where('from_status', OrderStatus::OrderAudit->value)
            ->where('to_status', OrderStatus::OrderApproved->value)
            ->orderByDesc('created_at')
            ->first();
    }

    public function getFirstOrder(): ?Order
    {
        return $this->orders()
            ?->whereNot('status', 'initial')
            ?->orderBy('rev')
            ?->first();
    }

    public function getLastOrder(): ?Order
    {
        return $this->orders()
            ?->whereNotIn("status", ["initial"])
            ?->orderBy('created_at', 'desc')
            ?->first();
    }

    /**
     * Sales order used for the QR delivery-note PDF ({@see SyncDeliveryNotePdfAction}) and the Filament delivery-note action.
     */
    public function resolveOrderForDeliveryNote(): ?Order
    {
        if ($this->getSubtype() === OrderSubtype::Part) {
            $draft = $this->draftOrder();
            if ($draft instanceof Order) {
                $uid = $draft->getUid();
                if ($uid !== null && $uid !== '' && $draft->main_id !== null) {
                    return $draft;
                }
            }
        }

        $order = $this->getOrderId() !== null
            ? Order::query()->find($this->getOrderId())
            : $this->getLastOrder();

        if (! $order instanceof Order) {
            return null;
        }

        if ($order->main_id === null) {
            return null;
        }

        $uid = $order->getUid();
        if ($uid === null || $uid === '') {
            return null;
        }

        return $order;
    }

    /**
     * Whether the latest non-initial sales order already had the order confirmation flow ({@see Order::$sent_at}).
     */
    public function latestSalesOrderHasOrderConfirmationSent(): bool
    {
        $order = $this->getLastOrder();
        if (! $order instanceof Order) {
            return false;
        }

        return $order->sent_at !== null;
    }

    /**
     * Order used for deposit invoice and payment-term checks: prefer status Pending, highest rev, then highest id.
     * {@see createInvoiceIfRequired()} intentionally uses {@see getLastOrder()} instead of this method for the final invoice.
     */
    public function getLatestOrderForInvoicing(): ?Order
    {
        $pending = $this->orders()
            ->whereIn('status', [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent])
            ->orderByDesc('rev')
            ->orderByDesc('id')
            ->first();

        if ($pending instanceof Order) {
            return $pending;
        }

        return $this->getLastOrder();
    }

    /**
     * Whether the billing party on this main has or will receive a deposit (aanbetalings-) invoice (50/50 terms).
     */
    public function billingCustomerReceivesOrWillReceiveDepositInvoice(): bool
    {
        $this->loadMissing(['billingCustomer', 'depositInvoice']);

        if ($this->getSubtype() === OrderSubtype::Service) {
            return false;
        }

        if ($this->depositInvoice !== null) {
            return true;
        }

        if (DepositInvoice::query()->where('main_id', $this->getId())->exists()) {
            return true;
        }

        $terms = $this->payment_terms instanceof PaymentTerms
            ? $this->payment_terms
            : PaymentTerms::tryFrom($this->getPaymentTermsValueForBillingContext());

        return PaymentTerms::requiresDepositInvoice($terms);
    }

    /**
     * Whether a deposit invoice still needs to be created and (later) emailed:
     * split payment term on the latest order, and no deposit_invoice row for this main yet.
     */
    public function needDepositInvoice(): bool
    {
        if ($this->getSubtype() === OrderSubtype::Service) {
            return false;
        }

        $order = $this->getLatestOrderForInvoicing();
        if (! $order instanceof Order) {
            return false;
        }

        if (! $order->needDepositInvoice()) {
            return false;
        }

        return ! DepositInvoice::query()
            ->where('main_id', $this->id)
            ->exists();
    }

    /**
     * Whether this main has at least one issued (sent) slot or deposit invoice row that is still unpaid.
     */
    public function hasUnpaidIssuedBillableInvoices(): bool
    {
        return Invoice::withoutGlobalScopes()
            ->where('main_id', $this->getKey())
            ->whereIn('type', [OrderType::Invoice->value, OrderType::DepositInvoice->value])
            ->whereNotIn('status', [
                OrderGeneralStatus::Initial->value,
                OrderGeneralStatus::Draft->value,
                OrderGeneralStatus::Cancelled->value,
            ])
            ->whereNotNull('sent_at')
            ->whereNull('paid_at')
            ->whereNull('credit_invoice_id')
            ->where('is_test', 0)
            ->exists();
    }

    /**
     * Order / quote used for purchasing
     */
    public function getOrderForPurchase(): Order|Quote|null
    {
        return $this->getFirstOrder()
            ?? $this->getLastOrder()
            ?? $this->draftOrder()
            ?? $this->getNewestApprovedQuote()
            ?? $this->getFallbackOrderForPurchaseWhenNoNonInitialOrder();
    }

    /**
     * When {@see getFirstOrder()} and {@see getLastOrder()} both yield null (e.g. only `initial` sales order rows),
     * still return the latest order row so purchase widgets never fall back to an unscoped {@see OrderProduct} query.
     */
    private function getFallbackOrderForPurchaseWhenNoNonInitialOrder(): ?Order
    {
        $row = $this->orders()
            ->reorder()
            ->orderByDesc('id')
            ->first();

        return $row instanceof Order ? $row : null;
    }

    public function getCanceledProducts(): HasMany
    {
        return $this->orderProducts()
            ->with(['product', 'supplier'])
            ->whereIn('status', [
                OrderProductStatus::Canceled->value,
                OrderProductStatus::AddToStock->value,
            ]);
    }

    /**
     * Generate the next main order UID. Format: A-{year}-{nr} e.g. A-2026-0001.
     */
    public static function getNextMainUid(): string
    {
        $year = date('Y');
        $prefix = 'A-' . $year . '-';
        $seqStart = (int) config('document_uids.main.sequence_start', 1);

        $lastNr = static::withoutGlobalScopes()
            ->where('type', OrderType::Main->value)
            ->whereNotNull('uid')
            ->where('uid', 'like', $prefix . '%')
            ->get()
            ->map(fn (self $main): int => (int) substr((string) $main->uid, strlen($prefix)))
            ->filter(fn (int $n): bool => $n > 0)
            ->max() ?? 0;

        $next = max($lastNr + 1, $seqStart);

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }


    public function getSerialNumber(): ?string
    {
        return $this->serial_number;
    }

    public function setSerialNumber(?string $serialNumber): self
    {
        $this->serial_number = $serialNumber;
        return $this;
    }

    public function getFittingOnHoldAppointmentId(): ?int
    {
        return null;
    }

    public function getDeliveryOnHoldAppointmentId(): ?int
    {
        return null;
    }

    public function getAssemblyOnHoldAppointmentId(): ?int
    {
        return null;
    }

    public function getFittingAt(): ?Carbon
    {
        return null;
    }

    public function isFittingCancellationSelectable(): bool
    {
        return $this->getAdvisorId() !== null;
    }

    public static function resolveForFittingEmailPreview(bool $forCustomerMail = true): self
    {
        return static::resolveForAdvisorOrderEmailPreview();
    }

    public static function resolveForDeliveryEmailPreview(bool $forCustomerMail = true): self
    {
        return static::resolveForAdvisorOrderEmailPreview();
    }

    public function resolvePackingSlipRecipientAddress(): ?Address
    {
        return $this->getShippingAddress();
    }

    public function getDeliveryAt(): ?Carbon
    {
        return null;
    }

    public function getServiceAt(): ?Carbon
    {
        return null;
    }

    /**
     * Main for advisor order-status e-mail previews (order check, ready for purchase).
     */
    public static function resolveForAdvisorOrderEmailPreview(): self
    {
        $main = static::query()
            ->whereNotNull('advisor_id')
            ->whereHas('orders', fn ($query) => $query->whereNotNull('uid'))
            ->with('advisor')
            ->latest('id')
            ->first();

        if ($main === null) {
            $main = static::query()
                ->whereHas('orders', fn ($query) => $query->whereNotNull('uid'))
                ->with('advisor')
                ->latest('id')
                ->first();
        }

        if ($main === null) {
            $main = static::query()
                ->whereNotNull('advisor_id')
                ->with('advisor')
                ->latest('id')
                ->first();
        }

        return $main ?? new static;
    }
}


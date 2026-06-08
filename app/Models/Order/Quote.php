<?php

namespace App\Models\Order;

use App\Enums\CustomerType;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Exceptions\OrderMissingDataException;
use App\Exceptions\OrderNotDuplicatedException;
use App\Exceptions\OrderNotSavedException;
use App\Exceptions\OrderOutOfStockException;
use App\Exceptions\QuoteAlreadyAcceptedException;
use App\Exceptions\QuoteRevisionAlreadyStartedException;
use App\Actions\SendQuoteMailAction;
use App\Models\Customer;
use App\Models\Document;
use App\Models\QuoteApproval;
use App\Models\User;
use App\Services\RecordLockService;
use App\Services\InventoryService;
use App\Services\PurchaseProductService;
use App\Traits\CartTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;


class Quote extends BaseOrder
{
    use CartTrait;

    private const string EMAIL_PREVIEW_UID = '99999';

    private const string EMAIL_PREVIEW_APPROVAL_UUID = '00000000-0000-4000-8000-000000000001';

    protected $table = 'orders';

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('type',
            fn(Builder $builder) => $builder->where('type', 'quote'));
        static::saving(function (self $quote): void {
            if ($quote->isDirty(['billing_customer_id', 'customer_id', 'subtype'])) {
                $quote->applyMainDefaultsBillingTermsFromContext();
            }
        });
    }

    /**
     * Create or update the linked quote row (same order type quote; {@code quote_company_id} points to source).
     */
    public function createOrUpdateQuote(?Quote $quote = null, bool $forceCreate = false): Quote
    {
        $quote = $quote ?? $this;
        $existingQuote = $quote->quoteCompany;

        if ($existingQuote && !$forceCreate) {
            // Update existing quote
            $existingQuote
                ->setType(OrderType::Quote)
                ->setQuoteId($quote->id)
                ->setIsTest($quote->customer->getIsTest())
                ->save();

            $existingQuote->generateDoc();
            $existingQuote->save();

            $quote = $existingQuote;
        } else {
            // Duplicate: new quote row points back to the source quote via quote_id
            $originalQuoteId = $quote->getId();
            $quote = $quote->duplicate();
            $quote
                ->setType(OrderType::Quote)
                ->setQuoteId($originalQuoteId)
                ->setIsTest($quote->customer->getIsTest())
                ->save();

            $quote->generateDoc();
            $quote->save();
        }

        $quote
            ->save();

        info('Created linked quote with ID ' . $quote->getId() .
            ', uid ' . $quote->getUid());

        return $quote;
    }

    /**
     * Generate a quote and send it to the company (create quote in admin panel)
     *
     * @throws OrderNotDuplicatedException
     * @throws Throwable
     */
    public function sendQuote(array $customMailData, bool $regenerateQuoteApproval = true): Quote
    {
        throw_if(!$this->hasCustomerOrCompany(),
            new OrderMissingDataException(
                'Order must be associated with a customer or company to create a quote')
        );

        throw_if($this->orderProducts()->count() == 0,
            new OrderMissingDataException(
                'Order must have at least one product to create a quote')
        );

        if (!$this->getUid()) {
            $this->setUid($this->getNewUid());
            $this->setRev(1);
        } else {
            if ($this->getQuoteId() !== null) {
                // Created via changeQuote (after approve) → next revision
                $this->setRev($this->quote->getRev() + 1);
            } else {
                // First time sending this concept → rev 1
                $this->setRev(1);
            }
        }

        $this
            ->setStatus(OrderGeneralStatus::Pending)
            ->setSentAt(now())
            ->setExpiresAt($this->calculateExpiresAt())
            ->setIsTest($this->resolveIsTestFlag());

        if ($this->getAuthorId() === null && Auth::id() !== null) {
            $this->setAuthorId((int) Auth::id());
        }

        $this->save();

        Document::createFromOrder($this);

        $this->refresh();

        // set other revisions to expired
        Quote::query()
            ->where('uid', '=', $this->getUid())
            ->whereNot('id', '=', $this->getId())
            ->whereIn('status', [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent])
            ->update(['status' => OrderGeneralStatus::Changed]);

        if ($regenerateQuoteApproval) {
            $this->regeneratePendingQuoteApproval();
        }

        $pdfMedia = $this->getFirstMedia('quote');
        if ($pdfMedia) {
            $customMailData['attachments'] = array_merge($customMailData['attachments'] ?? [], [
                ['path' => $pdfMedia->getPath(), 'name' => $pdfMedia->file_name, 'mime' => 'application/pdf'],
            ]);
        }

        app(SendQuoteMailAction::class)->execute($this, $customMailData);

        $this->main?->changeOrderStatus(OrderStatus::QuoteSent);

        info('Generated company quote with ID ' . $this->getId() .
            ', uid ' . $this->getUid());

        return $this;
    }


    /**
     * Generate a quote and send it to the customer
     *
     * @throws OrderNotDuplicatedException
     * @throws Throwable
     */
    public function send($silent = false): Quote
    {
        throw_if(!$this->hasCustomerOrCompany(),
            new OrderMissingDataException(
                'Order must be associated with a customer or company to create a quote')
        );

        throw_if($this->orderProducts()->count() == 0,
            new OrderMissingDataException(
                'Order must have at least one product to create a quote')
        );

        throw_if(!$this->hasCustomerOrCompany(),
            new OrderMissingDataException(
                'Order must have a valid customer or company')
        );

        throw_if(in_array($this->getStatus(), [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true),
            new QuoteAlreadyAcceptedException(
                'Quote is already created')
        );

        $this
            ->setStatus(OrderGeneralStatus::Pending)
            ->setSentAt(now())
            ->setExpiresAt($this->calculateExpiresAt())
            ->setIsTest($this->resolveIsTestFlag());

        // Only create a new UID if it's a new quote
        if (!$this->getUid()) {
            $this->setUid($this->getNewUid());
        }

        $this->save();

        // set other revisions to expired
        Quote::query()
            ->where('uid', '=', $this->getUid())
            ->whereNot('id', '=', $this->getId())
            ->whereIn('status', [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent])
            ->update(['status' => OrderGeneralStatus::Changed]);


        // Create company quote
        $this->createOrUpdateQuote();
        $this->refresh();


//        if (!$silent) {
//            if ($this->isFromSubsite()) {
//                if ($this->getSubsiteDirectQuote() === true) {
//                    $this->createDealerInvoice();
//                    (new SendSubsiteDealerDirectQuoteMailAction($this, $this->company->user->getEmail()))
//                        ->execute();
//                } else {
//                    (new SendSubsiteDealerQuoteMailAction($this, $this->company->user->getEmail()))
//                        ->execute();
//                }
//                (new SendSubsiteCustomerQuoteMailAction($this, $this->customer->getEmail(), true))
//                    ->execute();
//            } elseif ($this->hasSubsite()) {
//                (new SendSubsiteCustomerQuoteMailAction($this, $this->customer->getEmail()))
//                    ->execute();
//            } else {
//                (new SendQuotePortalConfirmMailAction($this, $this->company->user->getEmail()))
//                    ->execute();
//            }
//        }
        info('Generated quote with ID ' . $this->getId() .
            ', uid ' . $this->getUid());

        return $this;
    }

    /**
     * Copy the current quote to a new one, set the old one to expired. (Edit quote in Portal)
     *
     * @return Quote
     * @throws Throwable
     *
     * (changeQuote())
     */
    public function change(): Quote
    {
        throw_if($this->getType() !== OrderType::Quote,
            new OrderMissingDataException(
                "Order type must be 'quote' (was '{$this->getType()?->value}')")
        );

        throw_if(!$this->hasCustomerOrCompany(),
            new OrderMissingDataException(
                'Order must have a valid customer or company')
        );

        $rev = Quote::query()
                ->where('uid', '=', $this->getUid())
                ->max('rev') + 1;

        $newQuote = $this->duplicate()
            ->setStatus(OrderGeneralStatus::Draft)
            ->setQuoteId($this->getId())
            ->setSentAt(null)
            ->setRev($rev)
            ->setExpiresAt(null);

        $newQuote->save();

        // Set other revisions with the same UID to Changed
        Quote::query()
            ->where('uid', '=', $this->getUid())
            ->whereNot('id', '=', $newQuote->getId())
            ->update(['status' => OrderGeneralStatus::Changed]);

        // open_quote_id was previously stored on the company; company is now phased out

        $newQuote = Quote::where('id', $newQuote->getId())->first();

        info('Changed quote ' . $this->getId() . ' to ' . $newQuote->getId());

        return $newQuote;
    }

    /**
     * Copy the current quote to a new one, set the old one to expired. (Edit quote in Admin)
     *
     * @return Quote
     * @throws Throwable
     *
     * (changeQuote())
     */
    public function changeQuote(): Quote
    {
        throw_if($this->getType() !== OrderType::Quote,
            new OrderMissingDataException(
                "Order type must be 'quote' (was '{$this->getType()?->value}')")
        );

        return DB::transaction(function (): Quote {
            if ($this->main_id !== null) {
                Main::query()->whereKey($this->main_id)->lockForUpdate()->first();
            }

            $this->refresh();

            $allowedStatuses = [
                OrderGeneralStatus::Pending,
                OrderGeneralStatus::Sent,
                OrderGeneralStatus::Expired,
            ];

            if (! in_array($this->getStatus(), $allowedStatuses, true)) {
                throw new QuoteRevisionAlreadyStartedException();
            }

            $main = $this->main;
            $existingDraft = $main?->draftQuote();

            if ($existingDraft !== null
                && $existingDraft->getId() !== $this->getId()
                && filled($this->getUid())
                && $existingDraft->getUid() === $this->getUid()
            ) {
                $user = Auth::user();
                $blockingLock = $user instanceof User
                    ? app(RecordLockService::class)->getBlockingLock($existingDraft, $user)
                    : null;

                throw new QuoteRevisionAlreadyStartedException(
                    startedByUserName: $blockingLock?->user->getName(),
                );
            }

            $rev = Quote::query()
                ->where('uid', '=', $this->getUid())
                ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
                ->max('rev') + 1;

            $newQuote = $this->duplicate()
                ->setStatus(OrderGeneralStatus::Draft)
                ->setQuoteId($this->getId())
                ->setSentAt(null)
                ->setRev($rev)
                ->setAdditional(array_merge($this->getAdditional() ?? [], ['delivery_address_source' => 'company']))
                ->setExpiresAt(null);

            $newQuote->save();

            Quote::query()
                ->where('uid', '=', $this->getUid())
                ->whereNot('id', '=', $newQuote->getId())
                ->update(['status' => OrderGeneralStatus::Changed]);

            $newQuote = Quote::where('id', $newQuote->getId())->first();

            info('Changed quote ' . $this->getId() . ' to ' . $newQuote->getId());

            return $newQuote;
        });
    }

    public function isChangedQuote(): bool
    {
        return !is_null($this->getQuoteId());
    }

    public function createNewOrder(?BaseOrder $order = null): Order
    {
        $order = $order ?? $this;

        if (!empty($order->order)) {
            $order->order->delete();
            $order->refresh();
        }

        // Create company order (Order confirmation for company)
        $order = $order->duplicate(false)
            ->setRev(0)
            ->setExpiresAt(null)
            ->setType(OrderType::Order)
            ->setOrderId($order->getId());

        $order->save();

        // Load as proper Order model
        /** @var Order $order */
        $order = Order::where('id', $order->getId())->first();
        $order->resetOrderDateToToday();
        $order->generateDoc();
        $order->save();

        //$order->setOrderId($order->getId());
        //$order->save();

        return $order;
    }

    /**
     * Accept the quote and create an order from it
     *
     * @throws OrderNotDuplicatedException
     * @throws Throwable
     */
    public function accept($saveQuote = true): Order
    {
        throw_if($this->getType() !== OrderType::Quote,
            new OrderMissingDataException(
                "Order type must be 'quote' (was '{$this->getType()?->value}')")
        );

        throw_if($this->getStatus() === OrderGeneralStatus::Completed,
            new OrderMissingDataException(
                "Quote is already accepted")
        );

        DB::beginTransaction();
        try {
            if ($this->getStatus() === OrderGeneralStatus::Draft) {
                $this->send(true);
            }

            $this->setStatus(OrderGeneralStatus::Completed);

            throw_unless($this->save(),
                new OrderNotSavedException('Quote could not be set te completed')
            );

            $order = $this->duplicate()
                ->setSentAt(null)
                ->setRev(0)
                ->setIsTest($this->resolveIsTestFlag())
                ->setExpiresAt(null)
                ->setIsVerified(true)
                ->setType(OrderType::Order)
                ->setStatus(OrderGeneralStatus::Pending)
                ->setOrderStatus(OrderStatus::OrderAudit)
                ->setUid(null);

            $order->setQuoteId($saveQuote ? $this->getId() : null);
            $order->save();

            // Load as proper Order model
            $order = Order::where('id', $order->getId())->first();
            $order->resetOrderDateToToday();
            $order->setUid($order->getNewUid());
            $order->generateDoc();
            $order->save();

            // Reference the order to the quote
            $this->setOrderId($order->getId());

            throw_unless($this->save(),
                new OrderNotSavedException('Quote could not be referenced to order')
            );


            // Create company quote
            $this->createOrUpdateQuote();


            // Create company order
            $order = $this->createNewOrder($order);
            $order->refresh();


            // Reserve stock for the products (that use inventory management)
            // And send stock warning mails if needed
            $inventoryService = app(InventoryService::class);
            $inventoryService->reserveForOrder($order);


            if ($this->main?->getSubtype() !== OrderSubtype::Service
                && $this->main?->billingCustomer?->getType() !== CustomerType::UniekSporten
                && PaymentTerms::requiresDepositInvoice(
                    $order->payment_terms ?? PaymentTerms::tryFrom($order->getPaymentTermsValueForBillingContext()),
                )) {
                $order->createDepositInvoice();
                if ($this->resolveIsTestFlag() === 1) {
                    $order->setIsVerified(true);
                    $order->save();
                }
            } else {
                $order->save();
            }


            (new SendOrderPortalConfirmMailAction($order))->execute();

            DB::commit();
        } catch (OrderOutOfStockException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        info('Quote accepted, order created with ID ' . $order->getId() .
            ', from quote with id ' . $this->getId());

        return $order;
    }

    /**
     * Create an order from the quote and send mail to the company (when accepting quote made by admin)
     *
     * @param  bool  $reserveInventory  When false, skips {@see InventoryService::reserveForOrder()} (e.g. customer quote approval).
     * @throws OrderNotDuplicatedException
     * @throws Throwable
     */
    public function acceptQuote(bool $saveQuote = true, bool $reserveInventory = true): Order
    {
        throw_if($this->getType() !== OrderType::Quote,
            new OrderMissingDataException(
                "Order type must be 'quote' (was '{$this->getType()?->value}')")
        );

        throw_if($this->getStatus() === OrderGeneralStatus::Completed,
            new OrderMissingDataException(
                "Quote is already accepted")
        );

        DB::beginTransaction();
        try {
            // set Quote to completed
            $this->setStatus(OrderGeneralStatus::Completed);

            throw_unless($this->save(),
                new OrderNotSavedException('Quote could not be set te completed')
            );

            $this->updateQuoteApproval(Auth::id());

            $order = $this->duplicate()
                ->setRev(0)
                ->setIsTest($this->resolveIsTestFlag())
                ->setIsVerified(true)
                ->setSentAt(null)
                ->setSentAt(null)
                ->setType(OrderType::Order)
                ->setExpiresAt(null)
                ->setStatus(OrderGeneralStatus::Draft)
                ->setUid(null);

            $order->setQuoteId($saveQuote ? $this->getId() : null);
            $order->save();

            /** @var Order $order */
            // Load as proper Order model
            $order = Order::where('id', $order->getId())->first();
            $order->resetOrderDateToToday();
            $order->setUid($order->getNewUid());
            $order->setSubtype($this->main->getSubtype())
                ->setAdvisorId($this->main->getAdvisorId());
            $order->save();

            // Reference the order to the quote
            $this->setOrderId($order->getId());
            $this->setAuthorId(Auth::id() ?? null);

            throw_unless($this->save(),
                new OrderNotSavedException('Quote could not be referenced to order')
            );

            if ($reserveInventory) {
                $inventoryService = app(InventoryService::class);
                $inventoryService->reserveForOrder($order);
            }

            $order->save();

            $order?->main?->changeOrderStatus(OrderStatus::OrderDraft);

            app(PurchaseProductService::class)->update($order);

            DB::commit();
        } catch (OrderOutOfStockException $e) {
            DB::rollBack();

            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        info('Quote accepted, order created with ID ' . $order->getId() .
            ', from quote with id ' . $this->getId());

        return $order;
    }

    /**
     * At most one approval row per quote (DB: unique quote_id).
     *
     * @return HasOne<QuoteApproval, $this>
     */
    public function quoteApproval(): HasOne
    {
        return $this->hasOne(QuoteApproval::class, 'quote_id');
    }

    public function currentPendingQuoteApproval(): ?QuoteApproval
    {
        return $this->quoteApproval()
            ->whereNull('approved_at')
            ->first();
    }

    public function regeneratePendingQuoteApproval(): QuoteApproval
    {
        $alreadyApproved = $this->quoteApproval()
            ->whereNotNull('approved_at')
            ->first();
        if ($alreadyApproved !== null) {
            return $alreadyApproved;
        }

        $this->quoteApproval()
            ->whereNull('approved_at')
            ->delete();

        return $this->quoteApproval()->create([
            'uuid' => (string) Str::uuid(),
            'customer_name' => '',
        ]);
    }

    /**
     * Whether the quote's calendar expiry date is on or before today in the app timezone
     * (same rule as {@see \App\Console\Commands\ExpireQuotes}).
     */
    public function isValidityExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();
        if ($expiresAt === null) {
            return false;
        }

        $timezone = (string) config('app.timezone');
        $today = now()->timezone($timezone)->startOfDay();
        $expiryDay = $expiresAt->copy()->timezone($timezone)->startOfDay();

        return $expiryDay->lessThanOrEqualTo($today);
    }

    /**
     * Keep quote_approvals in sync with acceptQuote(): the customer flow sets approved_at (and signature)
     * before this runs; the admin flow updates pending rows or inserts a new approved row here.
     *
     * Rows already having approved_at set are left unchanged: no backfill of approved_by_user_id from
     * Auth, otherwise a logged-in staff member could be recorded as internal approver after external sign-off.
     */
    private function updateQuoteApproval(?int $actingUserId): void
    {
        /** @var QuoteApproval|null $approval */
        $approval = $this->quoteApproval()->first();

        if ($approval === null) {
            $name = $this->resolveQuoteApprovalCustomerName();
            $this->quoteApproval()->create([
                'uuid' => (string) Str::uuid(),
                'customer_name' => $name !== '' ? $name : '-',
                'approved_at' => now(),
                'approved_by_user_id' => $actingUserId,
            ]);

            return;
        }

        if ($approval->approved_at === null) {
            $approval->forceFill([
                'approved_at' => now(),
                'approved_by_user_id' => $actingUserId,
            ])->save();

            return;
        }
    }

    private function resolveQuoteApprovalCustomerName(): string
    {
        $additional = $this->getAdditional() ?? [];
        $billingName = $additional['billing_name'] ?? null;
        if (is_string($billingName) && trim($billingName) !== '') {
            return trim($billingName);
        }

        return $this->customer?->getName()
            ?? $this->billingCustomer?->getName()
            ?? $this->shippingCustomer?->getName()
            ?? '';
    }

    public function resolvePublicQuoteApproval(): ?QuoteApproval
    {
        $this->loadMissing('quoteApproval');

        return $this->currentPendingQuoteApproval() ?? $this->quoteApproval;
    }

    public function ensureQuoteApprovalForPublicLinks(): QuoteApproval
    {
        $approval = $this->resolvePublicQuoteApproval();

        if ($approval instanceof QuoteApproval) {
            return $approval;
        }

        return $this->quoteApproval()->create([
            'uuid' => (string) Str::uuid(),
            'customer_name' => $this->resolveQuoteApprovalCustomerName(),
        ]);
    }

    /**
     * Whether the offerte-mail should use the dealer template (QuoteMailDealer).
     *
     * Uses dealer template when To targets the dealer, or when the billing party is a dealer and
     * the mail is not explicitly to a distinct end customer (customer_id ≠ billing_customer_id).
     */
    public function shouldUseDealerQuoteMail(?string $primaryRecipientKey): bool
    {
        $this->loadMissing(['billingCustomer', 'customer']);

        if ($primaryRecipientKey === 'dealer') {
            return true;
        }

        if ($primaryRecipientKey === 'customer') {
            if ($this->billingCustomer?->getType() !== CustomerType::Dealer) {
                return false;
            }

            $billingId = $this->billing_customer_id !== null ? (int) $this->billing_customer_id : null;
            $customerId = $this->customer_id !== null ? (int) $this->customer_id : null;

            return $billingId === null
                || $customerId === null
                || $billingId === $customerId;
        }

        return $this->billingCustomer?->getType() === CustomerType::Dealer;
    }

    public function normalizeQuoteMailPrimaryRecipientKey(?string $primaryRecipientKey): ?string
    {
        if ($this->shouldUseDealerQuoteMail($primaryRecipientKey)) {
            return 'dealer';
        }

        if ($primaryRecipientKey === 'dealer' || $primaryRecipientKey === 'customer') {
            return $primaryRecipientKey;
        }

        return $primaryRecipientKey;
    }

    public function resolveQuoteMailClass(?string $primaryRecipientKey): string
    {
        $subtype = $this->main?->getSubtype() ?? $this->getSubtype() ?? OrderSubtype::Unit;
        $isDealer = $this->shouldUseDealerQuoteMail($primaryRecipientKey);

        return match (true) {
            $subtype === OrderSubtype::Service && $isDealer => \App\Mail\Service\QuoteMailDealer::class,
            $subtype === OrderSubtype::Service && ! $isDealer => \App\Mail\Service\QuoteMailCustomer::class,
            $subtype === OrderSubtype::Part && $isDealer => \App\Mail\Part\QuoteMailDealer::class,
            $subtype === OrderSubtype::Part && ! $isDealer => \App\Mail\Part\QuoteMailCustomer::class,
            $isDealer => \App\Mail\Unit\QuoteMailDealer::class,
            default => \App\Mail\Unit\QuoteMailCustomer::class,
        };
    }

    public static function resolveForEmailPreview(?OrderSubtype $subtype = null, bool $dealerOnly = false): self
    {
        $applySubtype = function (Builder $query) use ($subtype): Builder {
            if ($subtype !== null) {
                $query->whereHas('main', fn (Builder $mainQuery): Builder => $mainQuery->where('subtype', $subtype->value));
            }

            return $query;
        };

        $applyDealer = function (Builder $query): Builder {
            return $query->where(function (Builder $query): void {
                $query
                    ->whereHas(
                        'billingCustomer',
                        fn (Builder $customerQuery): Builder => $customerQuery->where('type', CustomerType::Dealer->value),
                    )
                    ->orWhereHas(
                        'main',
                        fn (Builder $mainQuery): Builder => $mainQuery->whereHas(
                            'billingCustomer',
                            fn (Builder $customerQuery): Builder => $customerQuery->where('type', CustomerType::Dealer->value),
                        ),
                    );
            });
        };

        $baseQuery = function () use ($applySubtype, $applyDealer, $dealerOnly): Builder {
            $query = $applySubtype(static::query())
                ->with(['customer', 'billingCustomer', 'main.billingCustomer', 'quoteApproval'])
                ->whereHas('main');

            if ($dealerOnly) {
                $query = $applyDealer($query);
            }

            return $query;
        };

        $quote = $baseQuery()
            ->where('status', '!=', OrderGeneralStatus::Draft)
            ->whereNotNull('uid')
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('customer_id')
                    ->orWhereNotNull('billing_customer_id');
            })
            ->latest('id')
            ->first();

        if ($quote === null) {
            $quote = $baseQuery()
                ->where('status', '!=', OrderGeneralStatus::Draft)
                ->whereNotNull('uid')
                ->latest('id')
                ->first();
        }

        if ($quote === null) {
            $quote = $baseQuery()
                ->latest('id')
                ->first();
        }

        if ($quote === null) {
            return static::makeEmailPreviewStub($subtype, $dealerOnly);
        }

        $quote->ensureQuoteApprovalForPublicLinks();
        $quote->load('quoteApproval');

        return $quote;
    }

    public static function makeEmailPreviewStub(?OrderSubtype $subtype = null, bool $dealerOnly = false): self
    {
        $subtype ??= OrderSubtype::Part;

        $dealerCustomer = static::makeEmailPreviewCustomer(
            name: 'Voorbeeld Dealer B.V.',
            type: CustomerType::Dealer,
            debtorNumber: '12345',
            email: 'offerte@voorbeeld-dealer.nl',
            firstName: 'Jan',
            lastName: 'de Vries',
        );
        $dealerCustomer->setRelation('billingAddress', null);

        $endCustomer = static::makeEmailPreviewCustomer(
            name: $dealerOnly ? 'Voorbeeld Eindklant' : null,
            type: CustomerType::B2C,
            debtorNumber: null,
            email: 'jan.jansen@voorbeeld.nl',
            firstName: 'Jan',
            lastName: 'Jansen',
        );

        $billingCustomer = $dealerOnly ? $dealerCustomer : $endCustomer;

        $main = new Main;
        $main->exists = false;
        $main->setSubtype($subtype);
        $main->setRelation('billingCustomer', $billingCustomer);
        $main->setRelation('customer', $endCustomer);

        $quote = new self;
        $quote->exists = false;
        $quote->setUid(self::EMAIL_PREVIEW_UID);
        $quote->setRev(0);
        $quote->setStatus(OrderGeneralStatus::Sent);

        if ($dealerOnly) {
            $quote->setAdditional([
                'invoice_address' => ['name' => 'Jan de Vries'],
            ]);
        }

        $quote->setRelation('billingCustomer', $billingCustomer);
        $quote->setRelation('customer', $endCustomer);
        $quote->setRelation('main', $main);

        $approvalCustomerName = $dealerOnly
            ? (string) ($endCustomer->getName() ?? 'Voorbeeld Klant')
            : (string) ($endCustomer->getName() ?? 'Jan Jansen');

        $approval = new QuoteApproval([
            'uuid' => self::EMAIL_PREVIEW_APPROVAL_UUID,
            'customer_name' => $approvalCustomerName,
        ]);
        $approval->exists = false;
        $quote->setRelation('quoteApproval', $approval);

        return $quote;
    }

    private static function makeEmailPreviewCustomer(
        ?string $name,
        CustomerType $type,
        ?string $debtorNumber,
        string $email,
        string $firstName,
        string $lastName,
    ): Customer {
        $customer = new Customer([
            'name' => $name,
            'type' => $type,
            'debtor_number' => $debtorNumber,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'salutation' => 'Dhr.',
        ]);
        $customer->exists = false;

        return $customer;
    }

    public function getPublicApprovalButtonHtml(): string
    {
        $approval = $this->resolvePublicQuoteApproval();
        if ($approval === null) {
            return '';
        }

        $url = route('approve-quote', ['uuid' => $approval->uuid], absolute: true);

        return '<a href="' . e($url) . '" style="display:inline-block;padding:12px 24px;background:#032d5c;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">Offerte bekijken en goedkeuren</a>';
    }

    public function getPublicDirectDownloadUrl(): string
    {
        $approval = $this->resolvePublicQuoteApproval();
        if ($approval === null) {
            return '';
        }

        if (Route::has('approve-quote.pdf')) {
            return route('approve-quote.pdf', ['uuid' => $approval->uuid], absolute: true);
        }

        return '';
    }

    public function getPublicDirectDownloadButtonHtml(): string
    {
        $url = $this->getPublicDirectDownloadUrl();
        if ($url === '') {
            return '';
        }

        return '<a href="' . e($url) . '" style="display:inline-block;padding:12px 24px;background:#032d5c;color:#fff;text-decoration:none;border-radius:4px;font-weight:600;">Offerte downloaden</a>';
    }

    public function getUidLabel(): string
    {
        return $this->isChangedQuote()
            ? '#' . $this->getUidFormatted()
            : 'Nieuwe offerte';
    }

    private function hasCustomerOrCompany(): bool
    {
        return $this->customer()->exists()
            || $this->billingCustomer()->exists()
            || $this->shippingCustomer()->exists();
    }

    private function resolveIsTestFlag(): int
    {
        return (int) (
            $this->customer?->getIsTest()
                ?? $this->billingCustomer?->getIsTest()
                ?? $this->shippingCustomer?->getIsTest()
                ?? $this->getIsTest()
        );
    }


    public function shouldDisplayGenerateInvoiceButton(): bool
    {
        return in_array($this->status, [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true)
            && $this->billingCustomer?->invoice_platform != null
            && $this->getDealerInvoice() == false;
    }
}

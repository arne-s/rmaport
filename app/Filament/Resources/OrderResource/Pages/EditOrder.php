<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Actions\SyncDeliveryNotePdfAction;
use App\Enums\CustomerType;
use App\Enums\DeliveryTime;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\OrderSubtype;
use App\Enums\PaymentTerms;
use App\Exceptions\OrderOutOfStockException;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Concerns\ManagesDeliveryAddressMode;
use App\Filament\Concerns\ManagesRecordLock;
use App\Filament\Support\RecordLockEditPage;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Actions\ApproveOrderEmailAction;
use App\Filament\Resources\Resource;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use App\Models\Address;
use App\Models\Country;
use App\Models\Customer;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Enums\OrderGeneralStatus;
use App\Filament\Forms\AddressFormSchema;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Support\OrderProductRepeaterAddAction;
use App\Filament\Support\OrderProductRepeaterAddBetweenAction;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\Order\Quote;
use App\Models\User;
use App\Models\Document;
use App\Services\InventoryService;
use App\Services\PurchaseProductService;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\Size;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;


/**
 * @property Order|Quote $record
 */
class EditOrder extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;
    use ManagesDeliveryAddressMode;
    use ManagesRecordLock;
    use PostcodeValidatorTrait;

    protected static string $resource = OrderResource::class;

    protected string $view = RecordLockEditPage::VIEW;

    public bool $showProductAddedToPurchaseBucketModal = false;

    public bool $showPurchaseProductSyncModal = false;

    /** @var array{title: string, description: string, lines: list<string>}|null */
    public ?array $purchaseProductSyncModal = null;
    protected $listeners = [

        'addOrderProduct' => 'addOrderProduct',
    ];

    /**
     * @var string|null $companyPurchasePriceDiscount Field to hold the company purchase price discount.
     */
    public ?string $companyPurchasePriceDiscount = null;

    /**
     * @var string|null $companySalesPriceDiscount Field to hold the company sales price discount.
     */
    public ?string $companySalesPriceDiscount = null;

    /**
     * @var Collection<int, OrderProduct> $orderProducts Collection to hold the order products in the form.
     */
    public ?Collection $orderProducts = null;

    /**
     * @var int[] $orderProductsToDelete Array to hold the IDs of order products that should be deleted when saving the quote.
     */
    public array $orderProductsToDelete = [];

    /** Product removed from the repeater; used so purchase-sync preview treats it as qty 0. */
    public ?int $purchaseSyncRemovedProductId = null;

    /** Suppresses purchase-sync preview modal while save() runs (form fill must not re-trigger it). */
    public bool $isSavingOrder = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> Used by mail modal document upload. */
    public array $documentFiles = [];

    /** Set during save when persisting a new revision (Initial → Pending). Used by mutateFormDataBeforeSave. */
    private bool $isSavingNewRevision = false;

    /** Suppress purchase-bucket warnings while loading existing lines on mount. */
    private bool $isHydratingOrderProducts = false;

    public function mount(int|string $record): void
    {
        if (! $this->mountRecordLockGate($record)) {
            return;
        }

        parent::mount($record);

        $this->completeRecordLockMount();

        $this->orderProducts ??= collect();
        $this->record->with(['customer', 'billingCustomer', 'main']);

        $this->fillForm();

        $this->hydrateAddressFormFromRecord();

        $this->loadOrderProducts();
        $this->formatCompanyPurchasePriceDiscount();
        $this->formatCompanySalesPriceDiscount();
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            Notification::make()
                ->title('Formulier ongeldig')
                ->body('Vul eerst alle verplichte velden in (bijv. Referentie) voordat je direct bestelt.')
                ->warning()
                ->send();
            throw $e;
        }
    }

    public function getMountedActions(): array
    {
        if (property_exists($this, 'mountedActions')) {
            $this->normalizeMountedActionsState($this->mountedActions);
        }
        return parent::getMountedActions();
    }

    public function callMountedAction(array $arguments = []): mixed
    {
        if (property_exists($this, 'mountedActions')) {
            $this->normalizeMountedActionsState($this->mountedActions);
        }

        return parent::callMountedAction($arguments);
    }

    private function normalizeMountedActionsState(mixed &$value): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => &$item) {
            if ($this->isLivewireArrayMetadata($item)) {
                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            if ($this->looksLikeMountedAction($item)) {
                $this->ensureApproveOrderEmailActionName($value, $key, $item);

                continue;
            }

            $this->normalizeMountedActionsState($item);
        }

        unset($item);
    }

    private function isLivewireArrayMetadata(mixed $item): bool
    {
        return is_array($item)
            && ($item['s'] ?? null) === 'arr'
            && count($item) === 1;
    }

    private function looksLikeMountedAction(array $item): bool
    {
        if (isset($item['name']) || isset($item['arguments']) || isset($item['context'])) {
            return true;
        }

        return $this->resolveMountedActionFormData($item) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMountedActionFormData(array $action): ?array
    {
        $data = $action['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        if ($this->isLivewireArrayMetadata($data)) {
            return null;
        }

        if (array_is_list($data)) {
            foreach ($data as $entry) {
                if (! is_array($entry) || $this->isLivewireArrayMetadata($entry)) {
                    continue;
                }

                return $entry;
            }

            return null;
        }

        return $data;
    }

    private function ensureApproveOrderEmailActionName(array &$parent, int|string $key, array $action): void
    {
        if (isset($action['name']) && $action['name'] !== '') {
            return;
        }

        $data = $this->resolveMountedActionFormData($action);
        if ($data === null) {
            return;
        }

        $isSendOrderForm = array_key_exists('message', $data)
            || array_key_exists('to_recipient', $data)
            || array_key_exists('subject', $data)
            || array_key_exists('from', $data);
        if ($isSendOrderForm) {
            $parent[$key]['name'] = 'send_order_email';
        }
    }

    protected function resolveRecord($key): Quote|Order
    {
        return Order::withoutGlobalScopes()->findOrFail($key);
    }

    public function updateCompanyPurchasePriceDiscount($value): void
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return;
        }

        $amount = round(abs((float)$normalized), 2);
        $this->companyPurchasePriceDiscount = number_format($amount, 2, ',', '');

        $this->record->setCompanyPurchasePriceDiscount(-$amount);
    }

    public function updateCompanySalesPriceDiscount($value): void
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return;
        }

        $amount = round(abs((float)$normalized), 2);
        $this->companySalesPriceDiscount = number_format($amount, 2, ',', '');

        $this->record->setCompanySalesPriceDiscount(-$amount);
    }

    /**
     * New order revision (status Initial, rev 0, same uid scheme): assign the next rev number
     * and set {@see $isSavingNewRevision} so {@see mutateFormDataBeforeSave} can move the row to Pending.
     */
    private function beginNewOrderRevisionPromotion(): bool
    {
        if (! $this->record instanceof Order) {
            return false;
        }

        if ($this->record->getStatus() !== OrderGeneralStatus::Initial) {
            return false;
        }

        if ($this->record->main_id === null || $this->record->getUid() === null || $this->record->getRev() !== 0) {
            return false;
        }

        $this->isSavingNewRevision = true;
        $maxRev = Order::query()
            ->where('uid', $this->record->getUid())
            ->max('rev');
        $nextRev = ($maxRev !== null ? (int) $maxRev : 0) + 1;
        $this->record->setRev($nextRev);

        return true;
    }

    /**
     * After persisting the new revision: release inventory on the previous revision, mark other rows Changed, reserve stock, serial number, event.
     */
    private function applyNewOrderRevisionPromotionAfterPersist(): void
    {
        if (! $this->record instanceof Order) {
            return;
        }

        $inventoryService = app(InventoryService::class);
        $nextRev = (int) $this->record->getRev();
        $previousOrder = Order::withoutGlobalScopes()
            ->where('uid', $this->record->getUid())
            ->where('rev', $nextRev - 1)
            ->first();

        if ($previousOrder !== null) {
            $inventoryService->releaseReservationForOrder($previousOrder);
        }

        Order::withoutGlobalScopes()
            ->where('type', OrderType::Order->value)
            ->where('uid', $this->record->getUid())
            ->whereNot('id', $this->record->getId())
            ->update(['status' => OrderGeneralStatus::Changed->value]);

        $inventoryService->reserveForOrder($this->record);

        $this->record->main?->orderEvents()->create([
            'type' => 'De order is aangepast: #' . $this->record->getRev(),
            'data' => [],
            'user_id' => Auth::id(),
        ]);

    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true, bool $syncLivewireFormState = true): void
    {
        $wasInitial = $this->record instanceof Order
            && $this->record->getStatus() === OrderGeneralStatus::Initial;

        $this->isSavingOrder = true;
        $this->dismissPurchaseProductSyncModal();

        try {
            // Set discounts
            $this->updateCompanyPurchasePriceDiscount($this->companyPurchasePriceDiscount);
            $this->updateCompanySalesPriceDiscount($this->companySalesPriceDiscount);

            // Preserve non-dehydrated field values before getState() strips them
            $preservedMainReferenceSync         = $this->data['main_reference_sync'] ?? null;
            $preservedMainReferenceInternalSync = $this->data['main_reference_internal_sync'] ?? null;
            $preservedOrderReference            = $this->data['orderReference'] ?? null;

            $data = $this->form->getState();
            if ($this->record->main_id !== null) {
                $data['customer_id'] = $this->record->customer_id;
            }
            $data['additional'] = $data['additional'] ?? [];

            // Restore non-dehydrated fields before form->fill() replaces state
            $data['main_reference_sync']          = $preservedMainReferenceSync;
            $data['main_reference_internal_sync'] = $preservedMainReferenceInternalSync;
            $data['orderReference']               = $preservedOrderReference;

            $isNewRevision = $this->beginNewOrderRevisionPromotion();

            if ($syncLivewireFormState) {
                $this->form->fill($data);
                // Save record
                parent::save();
            } else {
                // Bypass form->fill + parent::save to avoid triggering a full Livewire re-render
                // (which causes Alpine.js to reinitialize all repeater items, freezing the UI for seconds).
                $mutatedData = $this->mutateFormDataBeforeSave($data);
                $this->handleRecordUpdate($this->record, $mutatedData);
            }
            // Use saveQuietly for intermediate saves so minor attribute updates (status, uid,
            // additional cleanup) do not each trigger a full save cycle / side effects.
            $this->record->saveQuietly();

            if (empty($this->record->getUid())) {
                $this->record->setUid($this->record->getNewUid());
                $this->record->setRev(0);
                $this->record->saveQuietly();
            }

            // Save orderReference to order
            $this->record->order_reference = $preservedOrderReference ?? null;
            $this->record->saveQuietly();

            // Sync reference fields back to main when order has main_id
            if ($this->record->main_id !== null) {
                $this->record->refresh();
                $main = $this->record->main;
                if ($main instanceof Main) {
                    $main->setReference($preservedMainReferenceSync ?? '');
                    $main->setReferenceInternal($preservedMainReferenceInternalSync ?: null);
                    $main->applyBillingTermsFromSiblingDocument($this->record);
                    $main->save();
                }
            }

            foreach ($this->orderProducts as $orderProduct) {
                $formData = array_find($this->data['order_products'], fn ($op) => $op['id'] == $orderProduct['id']) ?? [];

                /** @var OrderProduct $op */
                $op = OrderProduct::find($orderProduct['id']);
                if (! empty($formData)) {
                    $this->applyOrderProductSalesPricingFromFormRow($op, $formData);
                }

                $op
                    ->setOrderId($this->record->id)
                    ->save();
            }

            // Delete order products that were removed in the form
            foreach ($this->orderProductsToDelete as $orderProductId) {
                OrderProduct::where('id', $orderProductId)->delete();
            }
            $this->orderProductsToDelete = [];

            $this->syncOrderProductSortFromFormState();

            // Apply VAT percentage from exact_vat_code to all order products (after order_id is set)
            $additional = $this->record->getAdditional() ?? [];
            $vatCode = $additional['exact_vat_code'] ?? null;
            if ($vatCode !== null && $vatCode !== '') {
                $vatPercent = (float)$this->getVatPercentageFromCode($vatCode);
                OrderProduct::where('order_id', $this->record->getId())->each(fn(OrderProduct $op) => $op->setVat($vatPercent)->save());
            }

            if ($this->record instanceof Order && $this->record->main_id !== null) {
                $this->record->unsetRelation('orderProducts');
                app(PurchaseProductService::class)->sync($this->record);
            }

            // Draft save with main: link order_id; main → Order: Concept when a sales order draft is linked (Unit always; Part/Service only if still in offerte-fase). Service may advance to inkoop via advanceMainToPurchaseForServiceAfterSave().
            if ($this->record instanceof Order && $this->record->main_id !== null && $this->record->getStatus() === OrderGeneralStatus::Draft) {
                $this->record->main?->updateQuietly(['order_id' => $this->record->getId()]);
                $main = $this->record->main;
                if ($main !== null && ! in_array($main->getSubtype(), [OrderSubtype::Part, OrderSubtype::Service], true)) {
                    $main->changeOrderStatus(OrderStatus::OrderDraft);
                } elseif ($main !== null) {
                    $linkedMainStatus = $main->getOrderStatus();
                    if ($linkedMainStatus !== null && OrderStatus::getMainStatusFor($linkedMainStatus) === OrderStatus::Quote) {
                        $main->changeOrderStatus(OrderStatus::OrderDraft);
                    }
                }
            }

            $this->advanceMainToPurchaseForServiceAfterSave();

            if ($isNewRevision) {
                $this->applyNewOrderRevisionPromotionAfterPersist();
            }

            if ($syncLivewireFormState) {
                $this->syncUnsavedChangesAlertAfterCustomSave();
            }
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        } catch (OrderOutOfStockException $e) {
            Notification::make()
                ->title('Artikelen niet op voorraad')
                ->body('Eén of meer artikelen in de offerte zijn niet op voorraad en kunnen niet worden besteld.')
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->isSavingNewRevision = false;
            $this->isSavingOrder = false;
        }
    }

    /**
     * Service: after saving the order draft, move the aanvraag to inkoop when still on Order: Concept.
     * Part and Unit stay on Order: Concept until the order is sent ({@see placeOrder()} → OrderSent → inkoop).
     */
    protected function advanceMainToPurchaseForServiceAfterSave(): void
    {
        if (! $this->record instanceof Order || $this->record->main_id === null) {
            return;
        }

        $main = $this->record->main;
        if ($main === null || $main->getSubtype() !== OrderSubtype::Service) {
            return;
        }

        $main->refresh();

        $current = $main->getOrderStatus();
        if ($current !== OrderStatus::OrderDraft) {
            return;
        }

        $main->changeOrderStatus(OrderStatus::OrderAwaitingPurchase);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['subtype'] = $this->record->getSubtype()?->value ?? $this->record->subtype;
        $data['advisor_id'] = $this->record->getAdvisorId();

        return $data;
    }

    private function isAdvisorRequiredForSubtype(Get $get): bool
    {
        if ($this->record->main_id !== null) {
            $this->record->loadMissing('main');

            return $this->record->main?->getSubtype() === OrderSubtype::Unit;
        }

        return $get('subtype') === OrderSubtype::Unit->value;
    }

    private function isAdvisorHiddenForSubtype(Get $get): bool
    {
        if ($this->record->main_id !== null) {
            $this->record->loadMissing('main');

            return $this->record->main?->getSubtype() === OrderSubtype::Part;
        }

        return $get('subtype') === OrderSubtype::Part->value;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->mergeDeliveryAddressModeIntoSaveData($data);

        $data['additional'] = array_merge(
            $this->record->getAdditional() ?? [],
            $data['additional'] ?? [],
        );

        unset($data['additional']['order_date']);

        if ($this->record instanceof Order && filled($data['created_at'] ?? null)) {
            $this->record->syncCreatedAtFromOrderDate($data['created_at']);
        }

        unset($data['created_at']);

        // Remove legacy address snapshots from additional (keep custom delivery snapshot fields)
        $isCustomDelivery = ($data['additional']['shipping_address_type_key'] ?? null) === self::DELIVERY_ADDRESS_MODE_CUSTOM;

        unset($data['additional']['billing_address_type_key']);

        if (! $isCustomDelivery) {
            unset(
                $data['additional']['shipping_address_type_key'],
                $data['additional']['shipping_name'],
            );
        }

        if ($this->record->getStatus() === OrderGeneralStatus::Initial) {
            $data['status'] = $this->isSavingNewRevision ? OrderGeneralStatus::Pending->value : OrderGeneralStatus::Draft->value;
        }

        if (
            $this->record instanceof Order
            && $this->record->payment_terms === null
            && (($data['payment_terms'] ?? null) === null || $data['payment_terms'] === '')
        ) {
            if ($this->record->main_id !== null) {
                $this->record->loadMissing('main');
                $main = $this->record->main;
                $data['payment_terms'] = $main !== null
                    ? $main->getPaymentTermsInheritedByChildren()->value
                    : $this->record->getPaymentTermsValueForBillingContext();
            } else {
                $data['payment_terms'] = $this->record->getPaymentTermsValueForBillingContext();
            }
        }

        return $data;
    }

    public function placeOrder(?array $emailData = null): void
    {
        DB::beginTransaction();
        try {
            $mainId = $this->record->main_id ?? null;

            /** @see createNewRevision(): new revision is Initial with rev 0; rev must be advanced before Pending or save() skips this step. */
            $promotedNewRevision = $this->beginNewOrderRevisionPromotion();

            $this->record->setStatus(OrderGeneralStatus::Sent);

            // syncLivewireFormState=false: skip form->fill to avoid client-side Alpine.js re-init before redirect
            $this->save(false, false, false);

            $activeOrder = $this->record;
            $activeOrder->setAdditional(array_merge($activeOrder->getAdditional() ?? [], $this->data['additional']));

            if ($activeOrder->getAuthorId() === null && Auth::id() !== null) {
                $activeOrder->setAuthorId((int) Auth::id());
            }

            $activeOrder->saveQuietly();

            Document::createFromOrder($activeOrder);

            if ($promotedNewRevision) {
                $this->applyNewOrderRevisionPromotionAfterPersist();
            } else {
                $inventoryService = app(InventoryService::class);
                $inventoryService->reserveForOrder($activeOrder);

                if ($activeOrder instanceof Order && $activeOrder->getUid()) {
                    Order::withoutGlobalScopes()
                        ->where('type', OrderType::Order->value)
                        ->where('uid', $activeOrder->getUid())
                        ->whereNot('id', $activeOrder->getId())
                        ->update(['status' => OrderGeneralStatus::Changed->value]);
                }
            }

            $activeOrder->main?->updateQuietly(['order_id' => $activeOrder->getId()]);

            $mainSubtype = $activeOrder->main?->getSubtype();
            $mainForStatus = $activeOrder->main;
            if ($mainSubtype === OrderSubtype::Part && $mainForStatus instanceof Main) {
                $mainForStatus->changeOrderStatus(OrderStatus::OrderSent);
            } elseif ($mainForStatus instanceof Main && ! in_array($mainSubtype, [OrderSubtype::Part, OrderSubtype::Service], true)) {
                if ($mainForStatus->usesUnitSimplifiedSalesFlow()) {
                    $mainForStatus->changeOrderStatus(OrderStatus::OrderSent);
                } else {
                    $activeOrder->main?->changeOrderStatus(OrderStatus::OrderAudit);
                }
            }

            if ($emailData !== null) {
                $activeOrder->refresh();
                $emailData = ApproveOrderEmailAction::refreshOrderConfirmationModalDataAfterOrderPersist($activeOrder, $emailData);
                $this->sendOrderConfirmationEmail($emailData, $activeOrder);

                if ($mainSubtype === OrderSubtype::Part) {
                    try {
                        app(SyncDeliveryNotePdfAction::class)->execute($activeOrder);
                    } catch (\Throwable $e) {
                        Log::error('SyncDeliveryNotePdfAction after Part order send failed', [
                            'order_id' => $activeOrder->getId(),
                            'exception' => $e,
                        ]);
                    }
                }

                $activeOrder->main?->orderEvents()->create([
                    'type' => 'De order is aangepast en verzonden: #' . $activeOrder->getRev(),
                    'data' => [],
                    'user_id' => Auth::id(),
                ]);
            }

            DB::commit();

            Notification::make()
                ->title('De order is verzonden.')
                ->success()
                ->send();

            $redirectUrl = $mainId ? route('filament.app.resources.mains.view', ['record' => $mainId]) : route('filament.app.resources.orders.index');
            $this->redirect($redirectUrl, navigate: $mainId ? false : true);
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->dispatch('scrollToFirstError');
            throw $e;
        } catch (OrderOutOfStockException $e) {
            DB::rollBack();
            Notification::make()
                ->title('Artikelen niet op voorraad')
                ->body('Eén of meer artikelen in de offerte zijn niet op voorraad en kunnen niet worden besteld.')
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            $body = 'De order kon niet worden geplaatst. Probeer het later opnieuw.';
            if (config('app.debug')) {
                $body .= ' ' . $e->getMessage();
            }
            Notification::make()
                ->title('Er is een fout opgetreden')
                ->body($body)
                ->danger()
                ->send();
        }
    }

    /**
     * @throws \Throwable
     */
    protected function sendOrderConfirmationEmail(array $data, \App\Models\Order\Order $order): void
    {
        app(\App\Actions\SendOrderConfirmationFromModalDataAction::class)->execute($order, $data);

        $toDisplay = is_array($data['to']) ? implode(', ', $data['to']) : $data['to'];
        Notification::make()
            ->title('Orderbevestiging verzonden')
            ->body("E-mail is verzonden naar: {$toDisplay}")
            ->success()
            ->send();
    }

    public function updatedDocumentFiles(): void
    {
        if (empty($this->documentFiles)) {
            return;
        }

        $allowedMimes = config('documents.allowed_mime_types', []);
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:' . implode(',', $allowedMimes) : 'file';
        $maxKb = 10240;

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|' . $mimetypesRule . '|max:' . $maxKb,
            ]);
        } catch (ValidationException $e) {
            $this->documentFiles = [];
            $message = $e->validator->errors()->first();
            Notification::make()
                ->title('Ongeldige bestanden.')
                ->body($message ?: 'Controleer het bestandstype en de bestandsgrootte.')
                ->danger()
                ->send();

            return;
        }

        $owner = $this->record->main ?? $this->record;
        $newMediaIds = [];
        $count = 0;
        $rejected = [];

        foreach ($this->documentFiles as $file) {
            if (!$file) {
                continue;
            }
            $mime = $file->getMimeType();
            if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }
            $media = $owner->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('documents');
            $newMediaIds[] = "media_{$media->id}";
            $count++;
        }

        $this->documentFiles = [];
        $owner->unsetRelation('media');

        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);

        if ($count > 0) {
            Notification::make()
                ->title($count === 1 ? 'Document geüpload.' : "{$count} documenten geüpload.")
                ->success()
                ->send();
        }
        if ($rejected !== []) {
            $names = implode(', ', array_slice($rejected, 0, 5));
            if (count($rejected) > 5) {
                $names .= ' … (+' . (count($rejected) - 5) . ' meer)';
            }
            Notification::make()
                ->title('Bestandstype niet toegestaan.')
                ->body('Overgeslagen: ' . $names)
                ->danger()
                ->send();
        }
    }

    protected function mergeNewUploadedAttachmentsIntoMountedAction(array $newMediaIds): void
    {
        if ($newMediaIds === [] || empty($this->mountedActions)) {
            return;
        }

        $index = null;
        foreach ($this->mountedActions as $key => $mounted) {
            if (!is_array($mounted)) {
                continue;
            }
            if (in_array($mounted['name'] ?? null, ['send_order_email', 'send_customer_email'], true)) {
                $index = $key;
                break;
            }
            if (isset($mounted['data']['attachments'])) {
                $index = $key;
                break;
            }
        }
        if ($index === null) {
            return;
        }

        if (!array_key_exists('data', $this->mountedActions[$index])) {
            $this->mountedActions[$index]['data'] = [];
        }

        $current = $this->mountedActions[$index]['data']['attachments'] ?? [];
        $current = is_array($current) ? $current : [];
        $merged = array_values(array_unique(array_merge($current, $newMediaIds)));
        $this->mountedActions[$index]['data']['attachments'] = $merged;
    }

    public function getRecordLockAdditionalBlade(): ?string
    {
        return view('filament.resources.orders.pages.partials.purchase-product-sync-modal', [
            'showPurchaseProductSyncModal' => $this->showPurchaseProductSyncModal,
            'purchaseProductSyncModal' => $this->purchaseProductSyncModal,
        ])->render();
    }

    protected function getFormActions(): array
    {
        return $this->formActionsUnlessRecordLockBlocked([
            Action::make('save')
                ->action(fn() => $this->save())
                ->extraAttributes([
                    'id' => 'save-button',
                ])
                ->label('Opslaan'),

            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->extraAttributes([
                    'class' => 'secondary',
                ])
                ->mountUsing(function (): void {
                    try {
                        // Same as EditQuote: persist via save(); billing_address_type stays the form key (e.g. customer-28).
                        $this->save(false, false, false);
                        $this->record->refresh();
                    } catch (ValidationException $e) {
                        $this->dispatch('scrollToFirstError');
                        throw $e;
                    }
                })
                ->modal()
                ->modalHeading('Preview')
                ->modalContent(function (): HtmlString {
                    $record = $this->getRecord();

                    $hasSnapshot = $record->documents()
                        ->whereNotNull('content')
                        ->where('content', '!=', '')
                        ->exists();

                    if ($hasSnapshot) {
                        $src = route('documents.show', ['orderId' => $record->getId(), 'preview' => 1]);

                        return new HtmlString(
                            '<div style="border-radius:5px; max-height:75vh; overflow:hidden;">'
                            . '<iframe id="order-preview-iframe" '
                            . 'style="border:0; width:100%; height:75vh; border-radius:5px; display:block;" '
                            . 'src="' . htmlspecialchars($src, ENT_QUOTES) . '" '
                            . '></iframe>'
                            . '</div>'
                        );
                    }

                    $record->loadMissing([
                        'orderProducts.product',
                        'customer.shippingAddress.country',
                        'customer.billingAddress.country',
                        'customer.address.country',
                        'billingCustomer.billingAddress.country',
                        'shippingCustomer.shippingAddress.country',
                        'shippingCustomer.billingAddress.country',
                        'shippingCustomer.address.country',
                        'order.author',
                        'main.author',
                        'author',
                    ]);

                    $html = view('order.order', [
                        'order' => $record,
                        'products' => $record->orderProducts,
                        'isPreview' => true,
                    ])->render();

                    $html = str_replace('<head>', '<head><style>div.order-wrapper { padding: 10px !important; }</style>', $html);

                    return new HtmlString(
                        '<div style="border-radius:5px; max-height:75vh; overflow:hidden;">'
                        . '<iframe id="order-preview-iframe" '
                        . 'style="border:0; width:100%; height:75vh; border-radius:5px; display:block;" '
                        . 'srcdoc="' . htmlspecialchars($html, ENT_QUOTES) . '" '
                        . 'sandbox="allow-same-origin allow-scripts allow-forms allow-modals" '
                        . '></iframe>'
                        . '</div>'
                    );
                })
                ->modalFooterActions([]),

            ApproveOrderEmailAction::make('send_order_email')
                ->label('Order versturen')
                ->hidden(fn (): bool => ($this->record->main?->getSubtype() ?? $this->record->getSubtype()) === OrderSubtype::Service),

            $this->getCancelFormAction()
                ->action(function (): void {
                    $redirectUrl = Resource::getRedirectToMainUrlForRecord($this->record);
                    if ($redirectUrl !== null) {
                        $this->redirect($redirectUrl, navigate: true);
                    } else {
                        $this->redirect(route('filament.app.resources.orders.index'));
                    }
                })
                ->extraAttributes(['class' => 'white']),
        ]);
    }

    /**
     * Add an order product to the form state and the $orderProducts collection.
     * Called when an order product is added via the product select in the repeater (event emmited by QuoteEditorModal) or when loading existing order products for the quote.
     */
    public function addOrderProduct(array $data): void
    {
        $orderProductId = $data['orderProductId'] ?? null;

        if (!$orderProductId) {
            return;
        }

        /** @var OrderProduct $orderProduct */
        $orderProduct = OrderProduct::find($orderProductId);
        if (!$orderProduct) {
            return;
        }

        // Get the repeater component and manipulate its state via the component API
        // This ensures Filament clears cached child schemas and re-renders properly
        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();


        if (! $this->isResyncingOrderProductsAfterSave) {
            $orderProduct
                ->setCompanyPurchasePriceBase(round($orderProduct->getCompanyPurchasePriceBase() + $orderProduct->getCompanyPurchasePriceAdditional(), 2))
                ->setCompanyPurchasePriceAdditional(0)
                ->setCompanySalesPriceBase(round($orderProduct->getCompanySalesPriceBase() + $orderProduct->getCompanySalesPriceAdditional(), 2))
                ->setCompanySalesPriceAdditional(0)
                ->save();
            $orderProduct->refresh();
        }


        $dealerDiscountPct = (string)round($this->getCurrentDealerDiscountPercentage(), 2);

        $discountPct = (float)($orderProduct->getCompanySalesPriceDiscountPercentage() ?: $dealerDiscountPct);
        $base = $orderProduct->getCompanySalesPriceBase();
        $qty = $this->qtyForOrderProductRepeaterRow($orderProduct);
        $subtotalBeforeDiscount = $base * $qty;
        $discount = $subtotalBeforeDiscount * ($discountPct / 100);
        $total = $subtotalBeforeDiscount - $discount;

        // Set order product values in the state
        $state["record-{$orderProduct->getId()}"] = [
            'id' => $orderProduct->getId(),
            'qty' => $this->qtyForOrderProductRepeaterRow($orderProduct),
            'product_allows_fractional_qty' => $this->productAllowsFractionalQtyForOrderProductRow($orderProduct),
            'product_id' => $orderProduct->getProductId(),
            'value' => $orderProduct->getValue(),
            'unit' => $orderProduct->product->getUnit()?->getLabel() ?? '',
            'attribute_summary_basic' => $orderProduct->getAttributeSummaryBasic(),
            'company_purchase_price_base' => $this->formatLineItemAmount($orderProduct->getCompanyPurchasePriceBase()),
            'company_sales_price_base' => $this->salesPriceBaseForInput($base),
            'company_sales_price_qty_total' => $this->formatLineItemAmount($subtotalBeforeDiscount),
            'company_sales_price_discount_percentage' => (string)round($discountPct, 2),
            'company_sales_price_total' => $this->formatLineItemAmount($total),
            'supplier_id' => $orderProduct->getSupplierId(),
        ];

        $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());

        foreach ($state as $key => $item) {
            $isEmpty = $item['id'] === 0 && empty($item['product_id']);
            $isDuplicate = ! str_starts_with($key, 'record-') && $item['id'] === $orderProduct->getId();

            if ($isEmpty || $isDuplicate) {
                unset($state[$key]);
            }
        }

        $repeater->state($state);

        if (! $this->isResyncingOrderProductsAfterSave) {
            $this->dispatch('update-totals');
        }
    }

    public function navigateBackToParentDocument(): void
    {
        $this->syncUnsavedChangesAlertAfterCustomSave();

        if ($this->record->main_id !== null) {
            $this->redirect(route('filament.app.resources.mains.view', ['record' => $this->record->main_id]));

            return;
        }

        $this->redirect(route('filament.app.resources.orders.index'));
    }

    public function loadOrderProduct(Get $get, Set $set)
    {
        /** @var Product $product */
        $product = Product::find($get('product_id'));
        if (!$product) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $orderVatPercent = $this->getVatPercentageFromCode($this->record->getAdditional()['exact_vat_code'] ?? null);

        $attributeSummaryBasicFromProduct = $product->getDescription() ?? '';

        $orderProductId = $get('id');

        if ($orderProductId && ($orderProduct = OrderProduct::find($orderProductId))) {
            // Load order product if already exists (when editing existing quote)
            /** @var OrderProduct $orderProduct */
            $orderProduct->update([
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => $this->normalizeQtyForProduct($product->getId(), $orderProduct->getQty() ?: 1),
                'company_purchase_price_base' => round($product->getCompanyPurchasePrice(), 2) ?: round($orderProduct->getCompanyPurchasePriceBase(), 2),
                'company_purchase_price_additional' => 0,
                'company_sales_price_base' => $this->salesPriceBaseForDatabase(
                    round($product->getCompanySalesPrice(), 2) ?: round($orderProduct->getCompanySalesPriceBase(), 2)
                ),
                'company_sales_price_additional' => 0,
                'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                'attribute_summary_company' => '',
                'attribute_summary' => '',
                'vat' => $orderVatPercent,
                'supplier_id' => $product->supplier?->id,
            ]);
        } else {
            // Create new order product with no order id (will be set when saving the quote)
            /** @var OrderProduct $orderProduct */
            $orderProduct = OrderProduct::create([
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => 1,
                'company_purchase_price_base' => round($product->getCompanyPurchasePrice(), 2),
                'company_purchase_price_additional' => 0,
                'company_purchase_price_subtotal' => round($product->getCompanyPurchasePrice(), 2),
                'company_sales_price_base' => $this->salesPriceBaseForDatabase(round($product->getCompanySalesPrice(), 2)),
                'company_sales_price_additional' => 0,
                'company_sales_price_subtotal' => round($product->getCompanySalesPrice(), 2),
                'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                'vat' => $orderVatPercent,
                'supplier_id' => $product->supplier?->id,
                'order_id' => null,
            ]);
            $orderProduct
                ->setFulfillmentTypeBasedOnProduct()
                ->save();

            $set('id', $orderProduct->id);
        }
        $orderProduct->refresh();

        $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());

        $set('qty', $this->normalizeQtyForProduct($product->getId(), $get('qty') ?? 1));
        $set('value', $orderProduct->getValue());
        $set('unit', $product->getUnit()?->getLabel() ?? '');
        $set('attribute_summary_basic', $attributeSummaryBasicFromProduct);
        $set('company_purchase_price_base', $this->formatLineItemAmount($orderProduct->getCompanyPurchasePriceBase()));
        $set('company_sales_price_base', $this->salesPriceBaseForInput($orderProduct->getCompanySalesPriceBase()));
        $dealerPct = (string)round($this->getCurrentDealerDiscountPercentage(), 2);
        $set('company_sales_price_discount_percentage', $dealerPct);
        $loadBase = (float)$orderProduct->getCompanySalesPriceBase();
        $loadDiscountPct = (float)$dealerPct;
        $loadSubtotal = $loadBase * 1;
        $loadDiscount = $loadSubtotal * ($loadDiscountPct / 100);
        $set('company_sales_price_qty_total', $this->formatLineItemAmount($loadSubtotal));
        $set('company_sales_price_total', $this->formatLineItemAmount($loadSubtotal - $loadDiscount));

        // Remove initial empty product
        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();
        $repeater->state(
            array_filter($state, fn($item) => $item['id'] !== 0)
        );
    }

    /**
     * Load existing order products for the quote and add them to the form state and the $orderProducts collection.
     */
    public function loadOrderProducts(): void
    {
        $this->isHydratingOrderProducts = true;

        try {
            foreach ($this->record->orderProducts as $orderProduct) {
                $this->addOrderProduct([
                    'orderProductId' => $orderProduct->getId(),
                    'productId' => $orderProduct->getProductId(),
                ]);
            }
        } finally {
            $this->isHydratingOrderProducts = false;
        }

        if ($this->orderProducts->isEmpty()) {
            $repeater = $this->form->getComponent('order_products');
            $repeater->state([[
                'qty' => 1,
                'id' => 0,
                'product_id' => null,
                'attribute_summary_basic' => '',
            ]]);
        }
    }

    protected function shouldWarnProductAddedToMainPurchaseBucket(): bool
    {
        if (! $this->record instanceof Order || $this->record->main_id === null) {
            return false;
        }

        $main = $this->record->main;
        if (! $main instanceof Main) {
            return false;
        }

        return $main->orderProducts()->exists();
    }

    public function openProductAddedToPurchaseBucketModal(): void
    {
        $this->showProductAddedToPurchaseBucketModal = true;
    }

    public function dismissProductAddedToPurchaseBucketModal(): void
    {
        $this->showProductAddedToPurchaseBucketModal = false;
    }

    /**
     * @return list<array{product_id: int, qty: float}>
     */
    protected function buildProposedLinesFromForm(): array
    {
        $lines = [];
        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();
        $pendingDeleteIds = array_flip($this->orderProductsToDelete);

        foreach (is_array($state) ? $state : [] as $row) {
            if (empty($row['product_id'])) {
                continue;
            }

            $orderProductId = (int) ($row['id'] ?? 0);
            if ($orderProductId > 0 && isset($pendingDeleteIds[$orderProductId])) {
                continue;
            }

            $lines[] = [
                'product_id' => (int) $row['product_id'],
                'qty' => (float) ($row['qty'] ?? 0),
            ];
        }

        return $lines;
    }

    protected function shouldEvaluatePurchaseProductSync(): bool
    {
        if ($this->isSavingOrder || $this->isHydratingOrderProducts) {
            return false;
        }

        if (! $this->record instanceof Order || $this->record->main_id === null) {
            return false;
        }

        $main = $this->record->main;

        return $main instanceof Main && $main->orderProducts()->exists();
    }

    public function maybeShowPurchaseProductSyncModal(?int $onlyProductId = null): void
    {
        if (! $this->shouldEvaluatePurchaseProductSync()) {
            $this->purchaseSyncRemovedProductId = null;

            return;
        }

        $proposedLines = $this->buildProposedLinesFromForm();
        $removedProductId = $this->purchaseSyncRemovedProductId;
        $this->purchaseSyncRemovedProductId = null;

        if ($removedProductId !== null && $removedProductId > 0) {
            $proposedLines = array_values(array_filter(
                $proposedLines,
                fn (array $line): bool => (int) $line['product_id'] !== $removedProductId,
            ));
            $onlyProductId = $removedProductId;
        }

        $service = app(PurchaseProductService::class);
        $preview = $service->preview($this->record, $proposedLines, $onlyProductId);

        if (! $preview['has_impact'] && $removedProductId !== null && $removedProductId > 0) {
            $preview = $service->previewForRemovedOrderProduct($this->record, $removedProductId);
        }

        if (! $preview['has_impact']) {
            return;
        }

        $this->purchaseProductSyncModal = $service->describeActionsForModal($preview);
        $this->showPurchaseProductSyncModal = true;
    }

    public function dismissPurchaseProductSyncModal(): void
    {
        $this->showPurchaseProductSyncModal = false;
        $this->purchaseProductSyncModal = null;
    }

    protected function formatCompanyPurchasePriceDiscount(): void
    {
        $this->companyPurchasePriceDiscount = $this->record->getCompanyPurchasePriceDiscount() !== null
            ? number_format(abs((float)$this->record->getCompanyPurchasePriceDiscount()), 2, ',', '')
            : null;
    }

    protected function formatCompanySalesPriceDiscount(): void
    {
        $this->companySalesPriceDiscount = $this->record->getCompanySalesPriceDiscount() !== null
            ? number_format(abs((float)$this->record->getCompanySalesPriceDiscount()), 2, ',', '')
            : null;
    }


    public function form(Schema $schema): Schema
    {
        $title = 'Nieuwe verkooporder';
        if ($this->record->getReference()) {
            $title = 'Conceptorder';
        }
        if ($this->record->getUid()) {
            $title = 'Order: #' . $this->record->getUidFormatted();
        }

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => $this->record->main_id !== null ? 'Aanvraag' : 'Order-overzicht',
                        'url' => $this->record->main_id !== null
                            ? route('filament.app.resources.mains.view', ['record' => $this->record->main_id])
                            : route('filament.app.resources.orders.index'),
                        'livewireBackMethod' => 'navigateBackToParentDocument',
                    ]),

                Section::make($title)
                    ->columns(12)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Group::make()
                            ->columnSpan(6)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                Select::make('customer_id')
                                    ->label('Klant')
                                    ->inlineLabel()
                                    ->options(fn () => $this->getCustomerOrDealerOptionsForForm(forKlantSelect: true))
                                    ->getSearchResultsUsing(fn (string $search) => $this->searchCustomerOrDealerOptions($search, forKlantSelect: true))
                                    ->getOptionLabelUsing(fn ($value): string => (int) $value === (int) $this->record->customer_id
                                        ? $this->klantSelectOrderRowLabel()
                                        : (Customer::query()->find($value)?->getName() ?? ''))
                                    ->searchable()
                                    ->required(fn() => $this->record->main_id === null)
                                    ->reactive()
                                    ->columnSpanFull()
                                    ->selectablePlaceholder(false)
                                    ->disabled(fn() => $this->record->main_id !== null)
                                    ->afterStateUpdated(fn(Set $set, Get $get, $state) => $this->syncHeaderCustomerForOrder($set, $get, $state))
                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap ter-attentie-van-field'])
                                    ->extraAttributes(['class' => 'ter-attentie-van-field']),
                            ]),

                        Grid::make(3)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'borderTop'])
                            ->schema([
                                Section::make('Klant')
                                    ->extraAttributes(['class' => 'section-klantgegevens'])
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->dehydrated()
                                    ->schema([
                                        View::make('filament.resources.quote-resource.customer-data')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make()
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->heading('')
                                    ->schema([
                                        Select::make('billing_customer_id')
                                            ->label('Factuuradres')
                                            ->inlineLabel()
                                            ->native(false)
                                            ->options(fn () => $this->getCustomerOrDealerOptionsForForm())
                                            ->getOptionLabelUsing(function ($value): string {
                                                if ($value === null || $value === '') {
                                                    return '';
                                                }
                                                $customer = Customer::query()->find((int) $value);

                                                return $customer !== null
                                                    ? $this->labelForCustomerOrDealerOption($customer, false)
                                                    : '';
                                            })
                                            ->getSearchResultsUsing(fn (string $search) => $this->searchCustomerOrDealerOptions($search))
                                            ->searchable()
                                            ->selectablePlaceholder(false)
                                            ->extraFieldWrapperAttributes(['class' => 'billing-customer-field'])
                                            ->columnSpanFull()
                                            ->live()
                                            ->afterStateUpdated(fn (Set $set, $state) => $this->syncBillingCustomerInvoiceFields($set, $state)),

                                        View::make('filament.resources.quote-resource.invoice-address')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make()
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->heading('')
                                    ->schema([
                                        Select::make('delivery_address_mode')
                                            ->label('Leveradres')
                                            ->inlineLabel()
                                            ->native(false)
                                            ->options(fn (Get $get): array => $this->buildDeliveryAddressModeOptions($get))
                                            ->default(self::DELIVERY_ADDRESS_MODE_INVOICE)
                                            ->selectablePlaceholder(false)
                                            ->disabled(fn (Get $get): bool => count($this->buildDeliveryAddressModeOptions($get)) <= 1)
                                            ->dehydrated(true)
                                            ->columnSpanFull()
                                            ->live()
                                            ->extraFieldWrapperAttributes(['class' => 'shipping-customer-field'])
                                            ->afterStateUpdated(fn (Set $set, Get $get, $state): mixed => $this->applyDeliveryAddressModeToShippingState(
                                                $set,
                                                $get,
                                                $state,
                                                fn (Set $s, int $id): mixed => $this->syncShippingCustomer($s, $id)
                                            )),

                                        Hidden::make('shipping_customer_id'),

                                        View::make('filament.resources.quote-resource.delivery-address')
                                            ->visible(fn (Get $get): bool => ($get('delivery_address_mode') ?? '') !== self::DELIVERY_ADDRESS_MODE_CUSTOM)
                                            ->columnSpanFull(),

                                        Group::make()
                                            ->visible(fn (Get $get): bool => ($get('delivery_address_mode') ?? '') === self::DELIVERY_ADDRESS_MODE_CUSTOM)
                                            ->extraAttributes(fn (Get $get): array => [
                                                'class' => ($get('delivery_address_mode') ?? '') === self::DELIVERY_ADDRESS_MODE_CUSTOM
                                                    ? 'address-contact-fields'
                                                    : 'hideForm',
                                            ])
                                            ->columns(12)
                                            ->columnSpanFull()
                                            ->schema([
                                                TextInput::make('additional.shipping_name')
                                                    ->label('Locatienaam')
                                                    ->columnSpanFull(),
                                                Group::make()
                                                    ->columnSpanFull()
                                                    ->statePath('additional.delivery_address')
                                                    ->columns(12)
                                                    ->schema(AddressFormSchema::fields()),
                                            ]),
                                    ]),
                            ]),

                        Grid::make(3)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'borderTop custom-form-design'])
                            ->schema([
                                Group::make()
                                    ->schema([
                                        Select::make('subtype')
                                            ->statePath('subtype')
                                            ->label('Type')
                                            ->inlineLabel()
                                            ->options(OrderSubtype::labels())
                                            ->live()
                                            ->required()
                                            ->extraFieldWrapperAttributes(fn() => $this->record->main_id !== null ? ['class' => 'appears-disabled'] : []),


                                        TextInput::make('main_reference_internal_sync')
                                            ->statePath('main_reference_internal_sync')
                                            ->label('Referentie (intern)')
                                            ->inlineLabel()
                                            ->visible(fn() => $this->record->main_id !== null)
                                            ->dehydrated(false),

                                        TextInput::make('main_reference_sync')
                                            ->statePath('main_reference_sync')
                                            ->label('Uw referentie (klant)')
                                            ->inlineLabel()
                                            ->required()
                                            ->visible(fn() => $this->record->main_id !== null)
                                            ->dehydrated(false),

                                        TextInput::make('order_comment')
                                            ->label('Opmerking')
                                            ->inlineLabel()
                                            ->maxLength(40),


                                        Hidden::make('vat_percentage')
                                            ->statePath('vat_percentage')
                                            ->default('21')
                                            ->dehydrated(false),
                                    ]),

                                Group::make()
                                    ->schema([

                                        TextInput::make('seller')
                                            ->statePath('seller')
                                            ->label('Verkoper')
                                            ->inlineLabel()
                                            ->formatStateUsing(fn() => auth()?->user()?->getName() ?? '-')
                                            ->disabled()
                                            ->dehydrated(false),


                                        Select::make('advisor_id')
                                            ->statePath('advisor_id')
                                            ->label('Adviseur')
                                            ->inlineLabel()
                                            ->options(fn (): array => User::advisorOptionsForSelect())
                                            ->getOptionLabelUsing(fn($value) => User::query()->find($value)?->getName() ?? '')
                                            ->required(fn (Get $get): bool => $this->isAdvisorRequiredForSubtype($get) && ! $this->isAdvisorHiddenForSubtype($get))
                                            ->hidden(fn (Get $get): bool => $this->isAdvisorHiddenForSubtype($get)),


                                        Select::make('additional.delivery_time')
                                            ->statePath('additional.delivery_time')
                                            ->label('Levertijd')
                                            ->inlineLabel()
                                            ->options(DeliveryTime::options())
                                            ->default(DeliveryTime::ThirteenWeeks->value)
                                            ->placeholder('-'),

                                        DatePicker::make('created_at')
                                            ->label('Orderdatum')
                                            ->inlineLabel()
                                            ->native(false)
                                            ->required()
                                            ->dehydrated(),

                                    ]),

                                Group::make()
                                    ->schema([


                                        Select::make('payment_terms')
                                            ->statePath('payment_terms')
                                            ->label('Betalingsvoorwaarden')
                                            ->inlineLabel()
                                            ->options(PaymentTerms::labels())
                                            ->default(PaymentTerms::Split50_50->value)
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                if (PaymentTerms::tryFrom((string) $state) === PaymentTerms::Advance100) {
                                                    $set('additional.exact_payment_condition', ExactPaymentCondition::NOT_APPLICABLE_CODE);
                                                }
                                            }),

                                        Select::make('additional.exact_payment_condition')
                                            ->statePath('additional.exact_payment_condition')
                                            ->label('Betalingsconditie')
                                            ->inlineLabel()
                                            ->options(ExactPaymentCondition::getPaymentConditionsAsOptions())
                                            ->required(),

                                        Select::make('additional.exact_vat_code')
                                            ->statePath('additional.exact_vat_code')
                                            ->label('BTW-code')
                                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                            ->inlineLabel()
                                            ->options(ExactVATCode::getSalesVatCodesAsOptions())
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, $state): void {
                                                $set('vat_percentage', (string)$this->getVatPercentageFromCode($state));
                                                $this->dispatch('update-totals');
                                            }),



                                    ]),
                            ]),

                        OrderProductsRepeater::make('order_products')
                            ->label('Artikelen')
                            ->default([])
                            ->minItems(1)
                            ->extraAttributes(['class' => 'orderProductsRepeater'])
                            ->table([
                                TableColumn::make('Aantal'),
                                TableColumn::make('Eenheid'),
                                TableColumn::make('Artikel'),
                                TableColumn::make('Specificaties / werkzaamheden'),
                                TableColumn::make(new HtmlString('<span>Inkoop</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make(new HtmlString('<span>Verkoop</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make(new HtmlString('<span>Verkoop totaal</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make('Korting'),
                                TableColumn::make(new HtmlString('<span>Nettoprijs</span> <span class="taxOverview">(excl. BTW)</span>')),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('value'),
                                Hidden::make('supplier_id'),

                                $this->orderProductAllowsFractionalQtyHiddenField(),

                                $this->configureOrderProductQtyField(
                                    TextInput::make('qty'),
                                    afterQtyUpdated: function (Get $get): void {
                                        $productId = (int) $get('product_id');
                                        $this->maybeShowPurchaseProductSyncModal($productId > 0 ? $productId : null);
                                    },
                                ),

                                TextInput::make('unit')
                                    ->label('Eenheid')
                                    ->disabled()
                                    ->dehydrated(true)
                                    ->live()
                                    ->extraFieldWrapperAttributes(['class' => 'input-unit']),

                                $this->configureOrderRepeaterProductSelect(ProductSelect::make('product_id'))
                                    ->required()
                                    ->live()
                                    ->extraAttributes(['class' => 'input-value'])
                                    ->extraFieldWrapperAttributes(['class' => 'product-select'])
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                        if (blank($state)) {
                                            return;
                                        }

                                        $this->loadOrderProduct($get, $set);
                                        $productId = (int) $state;
                                        $this->maybeShowPurchaseProductSyncModal($productId > 0 ? $productId : null);
                                    }),

                                Textarea::make('attribute_summary_basic')
                                    ->label('Specificaties / werkzaamheden')
                                    ->rows(3)
                                    ->formatStateUsing(fn($state) => arrayToTextareaString($state ?? []))
                                    ->extraFieldWrapperAttributes(['class' => 'input-specifications'])
                                    ->columnSpanFull(),


                                TextInput::make('company_purchase_price_base')
                                    ->label(new HtmlString('<span>Inkoop</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->readOnly()
                                    ->formatStateUsing(fn($state) => $this->formatLineItemAmount($state))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                $this->companySalesPriceBaseRepeaterField(),

                                TextInput::make('company_sales_price_qty_total')
                                    ->label(new HtmlString('<span>Verkoop totaal</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->formatStateUsing(fn($state, $get) => $this->formatLineItemAmount(
                                        $this->parseLineItemAmount($get('company_sales_price_base')) * ((float)($get('qty') ?? 1) ?: 1)
                                    ))
                                    ->dehydrated(false)
                                    ->disabled()
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell']),

                                TextInput::make('company_sales_price_discount_percentage')
                                    ->label('Korting')
                                    ->suffix('%')
                                    ->numeric()
                                    ->default(fn() => (string) round((float) ($this->record->billingCustomer?->discount_percentage ?? 0), 2))
                                    ->formatStateUsing(fn($state) => number_format((float)($state ?? 0), 2, '.', ''))
                                    ->afterStateUpdatedJs($this->updatePricesJs())
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell input-korting-pct']),

                                TextInput::make('company_sales_price_total')
                                    ->label(new HtmlString('<span>Nettoprijs</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->formatStateUsing(fn($state) => $this->formatLineItemAmount($state))
                                    ->belowContent(
                                        Text::make($this->calculateCompanyMarginTotalJs())
                                            ->js()
                                            ->extraAttributes(['style' => 'white-space: pre-line'])
                                    )
                                    ->disabled()
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell']),
                            ])
                            ->addAction(fn (Action $action) => OrderProductRepeaterAddAction::configure($action)
                                ->after(function (): void {
                                    $this->maybeShowPurchaseProductSyncModal();
                                }))
                            ->addBetweenAction(fn (Action $action) => OrderProductRepeaterAddBetweenAction::configure($action)
                                ->after(function (): void {
                                    $this->maybeShowPurchaseProductSyncModal();
                                }))
                            ->deleteAction(fn (Action $action) => $action
                                ->label('Product verwijderen')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->size(Size::ExtraSmall)
                                ->requiresConfirmation()
                                ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']))
                                ->action(function (array $arguments, Repeater $component): void {
                                    $items = $component->getRawState();
                                    $item = $items[$arguments['item']] ?? [];
                                    $orderProductId = (int) ($item['id'] ?? 0);
                                    $productId = (int) ($item['product_id'] ?? 0);

                                    if ($orderProductId > 0 && $this->orderProducts !== null) {
                                        $orderProductRow = $this->orderProducts->get($orderProductId);

                                        if (is_array($orderProductRow) && ! empty($orderProductRow['order_id'])) {
                                            $this->orderProductsToDelete[] = $orderProductId;
                                            $this->orderProducts->forget($orderProductId);
                                        } else {
                                            $this->orderProducts->forget($orderProductId);
                                            OrderProduct::where('id', $orderProductId)->delete();
                                        }
                                    }

                                    unset($items[$arguments['item']]);
                                    $component->rawState($items);
                                    $component->callAfterStateUpdated();

                                    $this->dispatch('update-totals');

                                    if ($productId > 0) {
                                        $this->purchaseSyncRemovedProductId = $productId;
                                        $this->maybeShowPurchaseProductSyncModal();
                                    }
                                }),
                            )
                            ->columnSpanFull(),

                        Section::make('Samenvatting')
                            ->columnSpanFull()
                            ->schema([
                                View::make('filament.resources.quote-resource.totals')
                                    ->statePath('totals_summary')
                                    ->columnSpanFull(),
                            ])
                    ]),
            ]);
    }

    protected function getInitialBillingAddressTypeKey(): string
    {
        return $this->record->resolveBillingAddressTypeKey();
    }

    protected function getInitialShippingAddressTypeKey(): string
    {
        return $this->record->resolveShippingAddressTypeKey();
    }

    protected function resolveNameFromTypeKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        if ($key === 'customer') {
            $this->record->loadMissing('customer');

            return $this->trimmedOrNull($this->record->customer?->getName());
        }

        if ($key === 'rd') {
            return $this->trimmedOrNull(Customer::getRdMobilityCustomer()->getName());
        }

        if (str_starts_with($key, 'customer-') || str_starts_with($key, 'company-')) {
            $id = (int) preg_replace('#^(customer|company)-#', '', $key);
            $customer = Customer::query()->find($id);

            return $this->trimmedOrNull($customer?->getName());
        }

        return null;
    }

    /**
     * Resolve {@see Address} for a billing/shipping type key (`customer-{id}` or `company-{id}` with the same customer id).
     *
     * @param  bool  $forDelivery  When true, use {@see Customer::getPhysicalDeliveryAddress()}; otherwise billing/invoice address.
     */
    protected function resolveAddressFromTypeKey(?string $key, bool $forDelivery = false): ?Address
    {
        if ($key === null || $key === '') {
            return null;
        }

        if ($key === 'custom') {
            return null;
        }

        if ($key === 'rd') {
            $av = Customer::getRdMobilityCustomer();

            return $forDelivery ? $av->getPhysicalDeliveryAddress() : $av->billingAddress;
        }

        if ($key === 'customer') {
            $this->record->loadMissing('customer');
            $customer = $this->record->customer;
            if ($customer === null) {
                return null;
            }

            return $forDelivery ? $customer->getPhysicalDeliveryAddress() : ($customer->billingAddress ?? $customer->address);
        }

        if (str_starts_with($key, 'customer-') || str_starts_with($key, 'company-')) {
            $id = (int) preg_replace('#^(customer|company)-#', '', $key);
            $customer = Customer::query()
                ->with(['billingAddress', 'shippingAddress', 'address'])
                ->find($id);
            if ($customer === null) {
                return null;
            }

            return $forDelivery ? $customer->getPhysicalDeliveryAddress() : ($customer->billingAddress ?? $customer->address);
        }

        return null;
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Hydrate form state additional.invoice_address and additional.delivery_address from the record's billing/shipping Address models.
     */
    protected function hydrateAddressFormFromRecord(): void
    {
        if ($this->record instanceof Order) {
            $legacyOrderDate = data_get($this->record->getAdditional(), 'order_date');

            if (filled($legacyOrderDate)) {
                $this->record->syncCreatedAtFromOrderDate($legacyOrderDate);
                $this->record->removeLegacyOrderDateFromAdditional()->saveQuietly();
                $this->record->refresh();
            }
        }

        $data = $this->data;
        $additional = $this->record->getAdditional() ?? [];
        $data['customer_id'] = $this->record->customer_id;
        $data['billing_address_type'] = $this->getInitialBillingAddressTypeKey();
        $data['shipping_address_type'] = $this->getInitialShippingAddressTypeKey();
        $data['delivery_address_mode'] = $this->resolveDeliveryAddressModeForForm();
        $data['additional'] = $data['additional'] ?? [];
        $data['additional']['billing_address_type_key'] = $data['billing_address_type'];
        $data['additional']['shipping_address_type_key'] = $additional['shipping_address_type_key'] ?? $data['shipping_address_type'];
        $data['additional']['quote_comment'] = $additional['quote_comment'] ?? null;
        $data['additional']['delivery_time'] = $additional['delivery_time'] ?? null;
        unset($data['additional']['order_date']);
        $data['created_at'] = $this->record instanceof Order
            ? $this->record->getOrderDate()
            : ($this->record->created_at ?? now());
        $data['additional']['exact_payment_condition'] = $additional['exact_payment_condition'] ?? null;
        $data['additional']['exact_vat_code'] = $additional['exact_vat_code'] ?? null;

        $billingKey = $data['billing_address_type'];
        $shippingKey = $data['additional']['shipping_address_type_key'];

        $data['additional']['billing_name'] = $this->resolveNameFromTypeKey($billingKey) ?? ($additional['billing_name'] ?? null);
        $data['additional']['shipping_name'] = $this->resolveNameFromTypeKey($shippingKey) ?? ($additional['shipping_name'] ?? null);

        $this->hydrateExactPaymentConditionFromBillingType($data);
        $this->hydrateExactVatCodeFromBillingType($data);
        $data['vat_percentage'] = (string) $this->getVatPercentageFromCode($data['additional']['exact_vat_code'] ?? null);
        if ($this->record->payment_terms === null) {
            if ($this->record->main_id !== null) {
                $this->record->loadMissing('main');
                $main = $this->record->main;
                $data['payment_terms'] = $main !== null
                    ? $main->getPaymentTermsInheritedByChildren()->value
                    : $this->record->getPaymentTermsValueForBillingContext();
            } else {
                $data['payment_terms'] = $this->record->getPaymentTermsValueForBillingContext();
            }
        }

        $billingAddr = $this->resolveAddressFromTypeKey($billingKey, false) ?? $this->record->billingAddress;
        if ($billingAddr !== null) {
            $data['additional']['invoice_address'] = $this->addressToFormArray($billingAddr);
        }

        $shippingAddr = $this->resolveAddressFromTypeKey($shippingKey, true) ?? $this->record->shippingAddress;
        if ($shippingAddr !== null) {
            $data['additional']['delivery_address'] = $this->addressToFormArray($shippingAddr);
        }

        if ($this->record->main_id !== null) {
            $this->record->loadMissing('main');
            $main = $this->record->main;
            $data['main_reference_sync']          = $main?->getReference() ?? '';
            $data['main_reference_internal_sync'] = $main?->getReferenceInternal() ?? '';
        }

        $data['orderReference'] = $this->record->order_reference ?? '';

        $this->form->fill($data);
    }


    /**
     * Set additional.exact_payment_condition from customer/company when not yet in additional, based on billing address type.
     */
    protected function hydrateExactPaymentConditionFromBillingType(array &$data): void
    {
        $add = &$data['additional'];
        if (($add['exact_payment_condition'] ?? '') === '' && $this->record->main_id !== null) {
            $this->record->loadMissing('main');
            $main = $this->record->main;
            if ($main !== null) {
                $add['exact_payment_condition'] = $main->getExactPaymentConditionInheritedByChildren();
            }
        }
        if (($add['exact_payment_condition'] ?? '') !== '') {
            return;
        }

        $invoiceCustomer = null;
        $billingKey = $data['billing_address_type'] ?? null;
        if ($billingKey === 'customer' && $this->record->customer !== null) {
            $invoiceCustomer = $this->record->customer;
        } else {
            $this->record->loadMissing('billingCustomer');
            $invoiceCustomer = $this->record->billingCustomer ?? $this->record->customer;
        }

        $add['exact_payment_condition'] = $this->record->resolveExactPaymentConditionCodeForBillingContext($invoiceCustomer);
    }

    /**
     * Return VAT percentage (0-100) for totals/order_products from an Exact VAT code. Default 21 if null or not found.
     */
    public function getVatPercentageFromCode(?string $code): float
    {
        if ($code === null || $code === '') {
            return 21.0;
        }
        $vatCode = ExactVATCode::where('code', $code)->first();
        if ($vatCode === null) {
            return 21.0;
        }
        $pct = (float)$vatCode->percentage;

        return $pct <= 1 ? $pct * 100 : $pct;
    }

    /**
     * Set additional.exact_vat_code from the billing customer when not yet set.
     */
    protected function hydrateExactVatCodeFromBillingType(array &$data): void
    {
        $add = &$data['additional'];
        if (($add['exact_vat_code'] ?? '') !== '') {
            return;
        }

        $this->record->loadMissing(['billingCustomer', 'customer']);
        $invoiceParty = $this->record->billingCustomer ?? $this->record->customer;
        if ($invoiceParty === null) {
            return;
        }

        $vat = $invoiceParty->getExactVatCode();
        if (is_string($vat) && $vat !== '') {
            $add['exact_vat_code'] = $vat;
        }
    }

    /**
     * @param array<string, mixed>|null $invoiceSnapshot
     * @param array<string, mixed>|null $deliverySnapshot
     */
    /**
     * @return array{name: ?string, street: ?string, house_number: ?string, house_number_addition: ?string, postcode: ?string, city: ?string, country_id: ?int, region_id: ?int, comment: ?string, additional: mixed}
     */
    private function addressToFormArray(Address $addr): array
    {
        return [
            'name' => $addr->name,
            'street' => $addr->street,
            'house_number' => $addr->house_number,
            'house_number_addition' => $addr->house_number_addition,
            'postcode' => $addr->postcode,
            'city' => $addr->city,
            'country_id' => $addr->country_id,
            'region_id' => $addr->region_id,
            'comment' => $addr->comment,
            'additional' => $addr->additional,
        ];
    }

    /**
     * Label for the Klant dropdown when it shows the order's linked customer:
     * {@see BaseOrder::getCustomerAddressDisplayName()} and {@see BaseOrder::getCustomerContactEmail()} (e.g. shipping address).
     */
    private function klantSelectOrderRowLabel(): string
    {
        $this->record->loadMissing('customer');
        $customer = $this->record->customer;
        if ($customer === null) {
            return '';
        }

        $display = $this->record->getCustomerAddressDisplayName();
        if ($display === '') {
            $display = $customer->getName();
        }

        $email = $this->record->getCustomerContactEmail();

        return $email !== '' ? $display . ' <' . $email . '>' : $display;
    }

    private function labelForCustomerOrDealerOption(Customer $c, bool $forKlantSelect): string
    {
        if (! $forKlantSelect) {
            return $c->getName();
        }

        $orderCustomerId = $this->record->customer_id;
        if ($orderCustomerId === null || (int) $c->id !== (int) $orderCustomerId) {
            return $c->getName();
        }

        $label = $this->klantSelectOrderRowLabel();

        return $label !== '' ? $label : $c->getName();
    }

    private function getCustomerOrDealerOptions(bool $forKlantSelect = false): array
    {
        $customers = Customer::query()
            ->active()
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where('type', '!=', CustomerType::Dealer->value)
            ->orderBy('name')
            ->limit(100)->get()
            ->mapWithKeys(fn (Customer $c): array => [$c->id => $this->labelForCustomerOrDealerOption($c, $forKlantSelect)]);

        $dealers = Customer::query()
            ->active()
            ->where('type', CustomerType::Dealer->value)
            ->orderBy('name')
            ->limit(100)->get()
            ->mapWithKeys(fn (Customer $c): array => [$c->id => $this->labelForCustomerOrDealerOption($c, $forKlantSelect)]);

        return $customers->all() + $dealers->all();
    }

    /**
     * @return array<int, string>
     */
    private function getCustomerOrDealerOptionsForForm(bool $forKlantSelect = false): array
    {
        $options = $this->getCustomerOrDealerOptions($forKlantSelect);
        $rawIds = [
            $this->record->billing_customer_id,
            $this->record->customer_id,
            is_array($this->data ?? null) ? ($this->data['billing_customer_id'] ?? null) : null,
            is_array($this->data ?? null) ? ($this->data['customer_id'] ?? null) : null,
        ];

        foreach ($rawIds as $raw) {
            if (! is_numeric($raw)) {
                continue;
            }
            $id = (int) $raw;
            if ($id <= 0 || isset($options[$id])) {
                continue;
            }
            $customer = Customer::query()->find($id);
            if ($customer !== null) {
                $options[$id] = $this->labelForCustomerOrDealerOption($customer, $forKlantSelect);
            }
        }

        ksort($options, SORT_NUMERIC);

        return $options;
    }

    private function searchCustomerOrDealerOptions(string $search, bool $forKlantSelect = false): array
    {
        $customers = Customer::query()
            ->active()
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where('type', '!=', CustomerType::Dealer->value)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)->get()
            ->mapWithKeys(fn (Customer $c): array => [$c->id => $this->labelForCustomerOrDealerOption($c, $forKlantSelect)]);

        $dealers = Customer::query()
            ->active()
            ->where('type', CustomerType::Dealer->value)
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)->get()
            ->mapWithKeys(fn (Customer $c): array => [$c->id => $this->labelForCustomerOrDealerOption($c, $forKlantSelect)]);

        return $customers->all() + $dealers->all();
    }

    /**
     * First line above invoice address: prefer {@see Address::getName()}, otherwise the customer display name.
     */
    protected function lineTerAttentieVan(?Address $address, ?Customer $customer): string
    {
        $fromAddress = trim((string) ($address?->getName() ?? ''));
        $fallback = trim((string) ($customer?->getName() ?? ''));
        $name = $fromAddress !== '' ? $fromAddress : $fallback;

        if ($name === '') {
            return '';
        }

        return 'Ter attentie van: ' . $name;
    }

    /**
     * Form `billing_address_type` key aligned with {@see BaseOrder::resolveBillingAddressTypeKey()} for the selected billing customer.
     */
    private function billingAddressTypeKeyForBillingCustomerId(int $billingCustomerId): string
    {
        $orderCustomerId = $this->record->getCustomerId();
        if ($orderCustomerId !== null && $billingCustomerId === (int) $orderCustomerId) {
            return 'customer';
        }

        return 'customer-' . $billingCustomerId;
    }

    /**
     * Invoice-address column: refresh invoice snapshot, VAT, discounts; sync shipping only when ship-to invoice party is selected.
     */
    private function syncBillingCustomerInvoiceFields(Set $set, mixed $state): void
    {
        $customerId = is_numeric($state) ? (int) $state : null;
        if ($customerId === null) {
            return;
        }

        $customer = Customer::query()->with(['billingAddress', 'shippingAddress', 'address'])->find($customerId);
        if ($customer === null) {
            return;
        }

        $billing = $customer->billingAddress;
        if ($billing !== null) {
            $set('additional.invoice_address', [
                'street' => $billing->street,
                'house_number' => $billing->house_number,
                'house_number_addition' => $billing->house_number_addition,
                'postcode' => $billing->postcode,
                'city' => $billing->city,
                'country_id' => $billing->country_id ?? Country::NL_ID,
            ]);
        }

        $set('additional.billing_name', $this->lineTerAttentieVan($billing, $customer));
        $set('additional.exact_payment_condition', $this->record->resolveExactPaymentConditionCodeForBillingContext($customer));

        $vatCode = $customer->getExactVatCode();
        $set('additional.exact_vat_code', $vatCode);
        $set('vat_percentage', (string) $this->getVatPercentageFromCode($vatCode));

        $discount = (float) ($customer->discount_percentage ?? 0);
        $this->applyDealerDiscountToOrderProducts($discount);
        $this->dispatch('update-totals');

        $set('billing_address_type', $this->billingAddressTypeKeyForBillingCustomerId($customerId));

        if (($this->data['delivery_address_mode'] ?? self::DELIVERY_ADDRESS_MODE_INVOICE) === self::DELIVERY_ADDRESS_MODE_INVOICE) {
            $set('shipping_customer_id', $customerId);
            $this->syncShippingCustomer($set, $customerId);
        }
    }

    private function syncBillingCustomerDefaults(Set $set, mixed $state): void
    {
        $customerId = is_numeric($state) ? (int) $state : null;
        if ($customerId === null) {
            return;
        }

        $customer = Customer::query()
            ->with(['billingAddress'])
            ->find($customerId);

        if ($customer === null) {
            return;
        }

        $vatCode = $customer->getExactVatCode();
        $discount = (float) ($customer->discount_percentage ?? 0);

        $paymentTermsValue = $customer->getType() === CustomerType::B2C
            && $this->record->getSubtype() === OrderSubtype::Unit
            ? PaymentTerms::Split50_50->value
            : PaymentTerms::Postpay->value;
        $set('payment_terms', $paymentTermsValue);
        $set('additional.exact_payment_condition', $this->record->resolveExactPaymentConditionCodeForBillingContext($customer));
        $set('additional.exact_vat_code', $vatCode);
        $set('vat_percentage', (string) $this->getVatPercentageFromCode($vatCode));

        $this->applyDealerDiscountToOrderProducts($discount);
        $this->dispatch('update-totals');

        $source = $customer->billingAddress;
        if ($source !== null) {
            $set('additional.invoice_address', [
                'street' => $source->street,
                'house_number' => $source->house_number,
                'house_number_addition' => $source->house_number_addition,
                'postcode' => $source->postcode,
                'city' => $source->city,
                'country_id' => $source->country_id ?? Country::NL_ID,
            ]);
        }

        $set('additional.billing_name', $this->lineTerAttentieVan($source, $customer));
        $set('billing_address_type', $this->billingAddressTypeKeyForBillingCustomerId($customerId));

        if ((int) $this->record->customer_id === $customerId) {
            $deliveryMode = self::DELIVERY_ADDRESS_MODE_CUSTOMER;
        } elseif ($customer->getType() === CustomerType::Dealer) {
            $deliveryMode = self::DELIVERY_ADDRESS_MODE_DEALER;
        } else {
            $deliveryMode = self::DELIVERY_ADDRESS_MODE_INVOICE;
        }

        $set('delivery_address_mode', $deliveryMode);
        $set('shipping_customer_id', $customerId);
        $this->syncShippingCustomer($set, $customerId);
    }

    private function syncHeaderCustomerForOrder(Set $set, Get $get, mixed $state): void
    {
        $customerId = is_numeric($state) ? (int) $state : null;
        if ($customerId === null) {
            return;
        }

        $mode = $get('delivery_address_mode');
        $mode = is_string($mode) && $mode !== '' ? $mode : self::DELIVERY_ADDRESS_MODE_INVOICE;

        if ($mode === self::DELIVERY_ADDRESS_MODE_CUSTOMER) {
            $set('shipping_customer_id', $customerId);
            $this->syncShippingCustomer($set, $customerId);
        }

        $this->dispatch('update-totals');
    }

    private function syncShippingCustomer(Set $set, mixed $state): void
    {
        $customerId = is_numeric($state) ? (int) $state : null;
        if ($customerId === null) {
            return;
        }

        $customer = Customer::query()
            ->with(['shippingAddress', 'billingAddress', 'address'])
            ->find($customerId);

        if ($customer === null) {
            return;
        }

        $source = $customer->getPhysicalDeliveryAddress();
        if ($source !== null) {
            $set('additional.delivery_address', $this->addressToFormArray($source));
        }

        $set('additional.shipping_name', $this->lineTerAttentieVan($source, $customer));
    }

    /**
     * Set company_sales_price_discount_percentage on every order_products line to the given value
     * (dealer percentage when billing is a company, or 0 when billing is the end customer).
     */
    private function applyDealerDiscountToOrderProducts(float $dealerDiscountPercentage): void
    {
        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();
        $hasUpdates = false;
        $rounded = (string) round($dealerDiscountPercentage, 2);

        foreach ($state as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $current = $item['company_sales_price_discount_percentage'] ?? null;
            $currentRounded = $current === null || $current === ''
                ? null
                : (string) round((float) $current, 2);

            if ($currentRounded === $rounded) {
                continue;
            }

            $state[$key]['company_sales_price_discount_percentage'] = $rounded;
            $hasUpdates = true;
        }

        if (! $hasUpdates) {
            return;
        }

        $repeater->state($state);
        $this->dispatch('update-totals');
    }

    private function getCurrentDealerDiscountPercentage(): float
    {
        $this->record->loadMissing('billingCustomer');

        return (float) ($this->record->billingCustomer?->discount_percentage ?? 0);
    }
}

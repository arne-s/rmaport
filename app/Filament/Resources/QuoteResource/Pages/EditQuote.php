<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Enums\CustomerType;
use App\Enums\DeliveryTime;
use App\Enums\OrderSubtype;
use App\Enums\PaymentTerms;
use App\Enums\ValidityPeriod;
use App\Filament\Actions\ImportBomAction;
use App\Filament\Support\OrderProductRepeaterAddAction;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Concerns\ManagesDeliveryAddressMode;
use App\Filament\Concerns\ManagesRecordLock;
use App\Filament\Support\RecordLockEditPage;
use App\Filament\Forms\AddressFormSchema;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\QuoteResource\Actions\SendQuoteEmailAction;
use App\Filament\Resources\Resource;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Models\Order\Main;
use App\Models\Order\Quote;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\User;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/** @property Quote $record */
class EditQuote extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;
    use ManagesDeliveryAddressMode;
    use ManagesRecordLock;

    use PostcodeValidatorTrait;

    protected static string $resource = QuoteResource::class;
    protected static ?string $title = 'Offerte bewerken';

    protected string $view = RecordLockEditPage::VIEW;

    protected $listeners = [
        'addOrderProduct' => 'addOrderProduct',
        'loadBomProducts' => 'loadBomProducts',
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

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> Used by mail modal document upload. */
    public array $documentFiles = [];

    protected function resolveRecord(int|string $key): Quote
    {
        return Quote::withoutGlobalScopes()->findOrFail($key);
    }

    public function mount(int|string $record): void
    {
        if (! $this->mountRecordLockGate($record)) {
            return;
        }

        parent::mount($record);

        $this->completeRecordLockMount();

        $this->orderProducts ??= collect();

        $this->record->with(['customer', 'billingCustomer']);

        // When editing a quote created by a Dealer in their portal, set the reference to the customer name if not already set.
        if (!$this->record->getIsAdminGenerated() && empty($this->record->getReference())) {
            $this->record->setReference($this->record->customer?->full_name ?? null);
        }

        $this->fillForm();

        $this->hydrateAddressFormFromRecord();

        $this->loadOrderProducts();
        $this->formatCompanyPurchasePriceDiscount();
        $this->formatCompanySalesPriceDiscount();
    }

    /**
     * Normalize mountedActions when the client sends a partial update (e.g. RichEditor only updates data.message)
     * that omits the action name, which would cause ActionNotResolvableException.
     */
    public function updatedMountedActions(mixed $value): void
    {
        $this->normalizeMountedActionsState($value);
        if (
            property_exists($this, 'mountedActions')
            && is_array($value)
            && $this->looksLikeMountedActionsState($value)
        ) {
            $this->mountedActions = $value;
        }
    }

    /**
     * Ensure mountedActions entries that look like the send-quote modal have a name so Filament can resolve them.
     * Called from getMountedActions() so the fix applies even when updatedMountedActions is not invoked.
     */
    public function getMountedActions(): array
    {
        if (property_exists($this, 'mountedActions')) {
            $this->normalizeMountedActionsState($this->mountedActions);
        }
        return parent::getMountedActions();
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
                $this->ensureSendQuoteEmailActionName($value, $key, $item);

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

    private function ensureSendQuoteEmailActionName(array &$parent, int|string $key, array $action): void
    {
        if (isset($action['name']) && $action['name'] !== '') {
            return;
        }

        $data = $this->resolveMountedActionFormData($action);
        if ($data === null) {
            return;
        }

        $isSendQuoteForm = array_key_exists('message', $data)
            || array_key_exists('to_recipients', $data)
            || array_key_exists('to_recipient', $data)
            || array_key_exists('to', $data)
            || array_key_exists('cc', $data)
            || array_key_exists('bcc', $data)
            || array_key_exists('attachments', $data)
            || array_key_exists('subject', $data)
            || array_key_exists('from', $data);
        if ($isSendQuoteForm) {
            $parent[$key]['name'] = 'send_quote_email';
        }
    }

    private function looksLikeMountedActionsState(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        $first = reset($value);
        if (!is_array($first)) {
            return false;
        }

        if (isset($first['name']) || isset($first['data']) || isset($first['arguments']) || isset($first['context'])) {
            return true;
        }

        $nestedFirst = reset($first);
        if (!is_array($nestedFirst)) {
            return false;
        }

        return isset($nestedFirst['name'])
            || isset($nestedFirst['data'])
            || isset($nestedFirst['arguments'])
            || isset($nestedFirst['context']);
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
     * @param bool $syncLivewireFormState When false, skips form->fill() + parent::save() to avoid a full
     *                                    Livewire re-render (Alpine.js reinit for all repeater rows).
     *                                    Use this from preview/send modal mountUsing to keep the UI fast.
     * @throws \Throwable
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true, bool $syncLivewireFormState = true): void
    {
        try {
            // Set discounts
            $this->updateCompanyPurchasePriceDiscount($this->companyPurchasePriceDiscount);
            $this->updateCompanySalesPriceDiscount($this->companySalesPriceDiscount);

            // Preserve non-dehydrated field values before getState() strips them (syncs with main.reference / main.reference_internal)
            $preservedMainReferenceSync         = $this->data['main_reference_sync'] ?? null;
            $preservedMainReferenceInternalSync = $this->data['main_reference_internal_sync'] ?? null;

            $data = $this->form->getState();
            if ($this->record->main_id !== null) {
                $data['customer_id'] = $this->record->customer_id;
            }
            $data['additional'] = $data['additional'] ?? [];

            $data['main_reference_sync']          = $preservedMainReferenceSync;
            $data['main_reference_internal_sync'] = $preservedMainReferenceInternalSync;

            if (empty($this->record->getUid())) {
                $this->record->setUid($this->record->getNewUid());
            }

            if ($syncLivewireFormState) {
                $this->form->fill($data);
                parent::save();
            } else {
                $mutatedData = $this->mutateFormDataBeforeSave($data);
                $this->handleRecordUpdate($this->record, $mutatedData);
            }

            if ($this->record->main_id) {
                $this->record->refresh();
                $main = $this->record->main;
                if ($main instanceof Main) {
                    $main->setQuoteId($this->record->getId());
                    $main->setReference($preservedMainReferenceSync ?? '');
                    $main->setReferenceInternal($preservedMainReferenceInternalSync ?: null);
                    $main->applyBillingTermsFromSiblingDocument($this->record);
                    $main->billing_customer_id = $this->record->billing_customer_id;
                    $main->shipping_customer_id = $this->record->shipping_customer_id;
                    if ($main->getSubtype() === OrderSubtype::Unit) {
                        $main->setAdvisorId($this->record->getAdvisorId());
                    }
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

            $this->syncOrderProductSortFromFormState();

            // Apply VAT percentage from exact_vat_code to all order products (after order_id is set)
            $additional = $this->record->getAdditional() ?? [];
            $vatCode = $additional['exact_vat_code'] ?? null;
            if ($vatCode !== null && $vatCode !== '') {
                $vatPercent = (float)$this->getVatPercentageFromCode($vatCode);
                OrderProduct::where('order_id', $this->record->getId())->each(fn(OrderProduct $op) => $op->setVat($vatPercent)->save());
            }

            if ($syncLivewireFormState) {
                $this->syncUnsavedChangesAlertAfterCustomSave();
            }
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        }
    }

    /**
     * Persist current form to record and save. Used when sending quote email so the PDF has latest data.
     * Call this from the send-email action when the user clicks "Versturen" (not when opening the modal).
     */
    public function persistFormAndSaveForSending(): void
    {
        $this->save(false, false, false);
        $this->record->refresh();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->mergeDeliveryAddressModeIntoSaveData($data);

        $data['additional'] = array_merge(
            $this->record->getAdditional() ?? [],
            $data['additional'] ?? [],
        );

        // Remove legacy address type keys (keep custom delivery snapshot fields)
        $isCustomDelivery = ($data['additional']['shipping_address_type_key'] ?? null) === self::DELIVERY_ADDRESS_MODE_CUSTOM;

        unset($data['additional']['billing_address_type_key']);

        if (! $isCustomDelivery) {
            unset(
                $data['additional']['shipping_address_type_key'],
                $data['additional']['shipping_name'],
            );
        }

        if ($this->record->getStatus() === \App\Enums\OrderGeneralStatus::Initial) {
            $data['status'] = \App\Enums\OrderGeneralStatus::Draft->value;
        }

        if (($this->record->author_id ?? null) === null && auth()->id() !== null) {
            $data['author_id'] = auth()->id();
        }

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['subtype'] = $this->record->getSubtype()?->value ?? $this->record->subtype;
        $data['advisor_id'] = $this->record->getAdvisorId();

        if ($this->record->main_id !== null) {
            $this->record->loadMissing('main');

            $data['subtype'] = $this->record->main?->getSubtype()?->value ?? $data['subtype'];

            if ($data['advisor_id'] === null) {
                $data['advisor_id'] = $this->record->main?->getAdvisorId();
            }
        }

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

    protected function getFormActions(): array
    {
        return $this->formActionsUnlessRecordLockBlocked([
            $this->getSubmitFormAction()
                ->label('Opslaan')
                ->extraAttributes([
                    'id' => 'submit-button',
                ]),

            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->extraAttributes([
                    'class' => 'secondary',
                ])
                ->mountUsing(function (): void {
                    try {
                        $this->save(false, false, false);
                        $this->record->refresh();
                        Document::regenerateQuotePdfFromLiveOrder($this->record);
                    } catch (ValidationException $e) {
                        $this->dispatch('scrollToFirstError');
                        throw $e;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                })
                ->modal()
                ->modalHeading('Preview')
                ->modalContent(function (): HtmlString {
                    $record = $this->getRecord();
                    $src = route('documents.show', ['orderId' => $record->getId(), 'preview' => 1]);

                    return new HtmlString(
                        '<div style="border-radius:5px; max-height:75vh; overflow:hidden;">'
                        . '<iframe id="quote-preview-iframe" '
                        . 'style="border:0; width:100%; height:75vh; border-radius:5px; display:block;" '
                        . 'src="' . htmlspecialchars($src, ENT_QUOTES) . '" '
                        . '></iframe>'
                        . '</div>'
                    );
                })
                ->modalFooterActions([]),

            SendQuoteEmailAction::make('send_quote_email'),

            $this->getCancelFormAction()
                ->action(function (): void {
                    $redirectUrl = Resource::getRedirectToMainUrlForRecord($this->record);
                    if ($redirectUrl !== null) {
                        $this->redirect($redirectUrl, navigate: true);
                    } else {
                        $this->redirect(route('filament.app.resources.quotes.index'));
                    }
                })
                ->extraAttributes(['class' => 'white']),
        ]);
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

    /**
     * @param array<int, string> $newMediaIds
     */
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
            if (($mounted['name'] ?? null) === 'send_quote_email') {
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

        $this->redirect(route('filament.app.resources.quotes.index'));
    }

    public function loadBomProducts(?array $orderProducts)
    {
        foreach ($orderProducts as $orderProductId) {
            $this->addOrderProduct([
                'orderProductId' => $orderProductId,
            ]);
        }

        $this->dispatch('update-totals');
    }

    public function loadOrderProduct(Get $get, Set $set)
    {
        /** @var Product $product */
        $product = Product::find($get('product_id'));
        if (!$product) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $quoteVatPercent = $this->getVatPercentageFromCode($this->record->getAdditional()['exact_vat_code'] ?? null);

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
                'vat' => $quoteVatPercent,
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
                'vat' => $quoteVatPercent,
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
        foreach ($this->record->orderProducts as $orderProduct) {
            $this->addOrderProduct([
                'orderProductId' => $orderProduct->getId(),
                'productId' => $orderProduct->getProductId(),
            ]);
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
        $title = 'Nieuwe offerte';
        if ($this->record->getReference()) {
            $title = 'Conceptofferte';
        }
        if ($this->record->getUid()) {
            $uid = $this->record->getUidFormatted();
            $title = "Offerte: #$uid";
        }

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => $this->record->main_id !== null ? 'Aanvraag' : 'Offerte-overzicht',
                        'url' => $this->record->main_id !== null
                            ? route('filament.app.resources.mains.view', ['record' => $this->record->main_id])
                            : route('filament.app.resources.quotes.index'),
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
                                    ->options(fn () => $this->getCustomerOrDealerOptionsForForm())
                                    ->getSearchResultsUsing(fn(string $search) => $this->searchCustomerOrDealerOptions($search))
                                    ->getOptionLabelUsing(fn ($value): string => (int) $value === (int) $this->record->customer_id
                                        ? $this->record->getCustomerAddressDisplayName()
                                        : (Customer::query()->find($value)?->getName() ?? ''))
                                    ->searchable()
                                    ->required(fn() => $this->record->main_id === null)
                                    ->reactive()
                                    ->columnSpanFull()
                                    ->selectablePlaceholder(false)
                                    ->disabled(fn() => $this->record?->main_id !== null)
                                    ->afterStateUpdated(fn (Set $set, Get $get, $state) => $this->syncHeaderCustomerForQuote($set, $get, $state))
                                    ->extraAttributes(['class' => 'ter-attentie-van-field'])
                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap ter-attentie-van-field']),
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

                                                return Customer::query()->find((int) $value)?->getName() ?? '';
                                            })
                                            ->getSearchResultsUsing(fn(string $search) => $this->searchCustomerOrDealerOptions($search))
                                            ->searchable()
                                            ->selectablePlaceholder(false)
                                            ->columnSpanFull()
                                            ->live()
                                            ->extraFieldWrapperAttributes(['class' => 'billing-customer-field'])
                                            ->afterStateUpdated(fn (Set $set, $state) => $this->syncBillingCustomer($set, $state)),

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
                                            ->extraFieldWrapperAttributes(['class' => 'shipping-customer-field'])
                                            ->live()
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
                                            ->getOptionLabelUsing(fn ($value) => User::query()->find($value)?->getName() ?? '')
                                            ->nullable()
                                            ->placeholder('-'),

                                        Select::make('additional.delivery_time')
                                            ->statePath('additional.delivery_time')
                                            ->label('Levertijd')
                                            ->inlineLabel()
                                            ->options(DeliveryTime::options())
                                            ->default(fn () => ($this->record->main?->getSubtype() ?? $this->record->getSubtype()) === OrderSubtype::Unit
                                                ? DeliveryTime::ThirteenWeeks->value
                                                : null)
                                            ->placeholder('-'),
                                        Select::make('validity_period')
                                            ->statePath('validity_period')
                                            ->label('Geldigheid offerte')
                                            ->required()
                                            ->inlineLabel()
                                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                            ->options(ValidityPeriod::labels())
                                            ->searchable(false),

                                    ]),

                                Group::make()
                                    ->schema([

                                        Select::make('payment_terms')
                                            ->statePath('payment_terms')
                                            ->label('Betalingsvoorwaarden')
                                            ->inlineLabel()
                                            ->options(PaymentTerms::labels())
                                            ->default(PaymentTerms::Postpay->value)
                                            ->nullable()
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
                            ->hintAction(
                                ImportBomAction::make('import_bom')
                            )
                            ->table([
                                TableColumn::make('Aantal'),
                                TableColumn::make('Eenheid'),
                                TableColumn::make('Artikel'),
                                TableColumn::make('Specificaties'),
                                TableColumn::make(new HtmlString('<span>Inkoop</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make(new HtmlString('<span>Verkoop</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make(new HtmlString('<span>Verkoop totaal</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make(new HtmlString('<span>Korting</span>')),
                                TableColumn::make(new HtmlString('<span>Nettoprijs</span> <span class="taxOverview">(excl. BTW)</span>')),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('value'),
                                Hidden::make('supplier_id'),

                                $this->orderProductAllowsFractionalQtyHiddenField(),

                                $this->configureOrderProductQtyField(TextInput::make('qty')),

                                TextInput::make('unit')
                                    ->label('Eenheid')
                                    ->disabled()
                                    ->dehydrated(true)
                                    ->live()
                                    ->extraFieldWrapperAttributes(['class' => 'input-unit']),

                                $this->configureOrderRepeaterProductSelect(ProductSelect::make('product_id'))
                                    ->required()
                                    ->extraAttributes(['class' => 'input-value'])
                                    ->extraFieldWrapperAttributes(['class' => 'product-select'])
                                    ->afterStateUpdated(function (Get $get, Set $set) {
                                        $this->loadOrderProduct($get, $set);
                                    }),

                                Textarea::make('attribute_summary_basic')
                                    ->label('Specificaties')
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
                                    ->default(fn() => true
                                        ? (string)round((float)($this->record->billingCustomer?->discount_percentage ?? 0), 2)
                                        : '0')
                                    ->formatStateUsing(fn($state) => number_format((float)($state ?? 0), 2, '.', ''))
                                    ->afterStateUpdatedJs($this->updatePricesJs())
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell input-korting-pct']),

                                TextInput::make('company_sales_price_total')
                                    ->label(new HtmlString('<span>Nettoprijs</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->formatStateUsing(fn($state) => $this->formatLineItemAmount($state))
                                    ->disabled()
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell']),
                            ])
                            ->addAction(fn (Action $action) => OrderProductRepeaterAddAction::configure($action))
                            ->deleteAction(fn(Action $action) => $action
                                ->label('Product verwijderen')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->size(Size::ExtraSmall)
                                ->requiresConfirmation()
                                ->before(function (array $arguments, Repeater $component, mixed $state) {
                                    $orderProductId = $state[$arguments['item']]['id'];

                                    // If order product is linked to a quote, keep track of it in order_products_to_delete and delete it after saving the quote
                                    if ($this->orderProducts->get($orderProductId)['order_id'] ?? false) {
                                        $this->orderProductsToDelete[] = $orderProductId;
                                        $this->orderProducts->forget($orderProductId);
                                    } else {
                                        // If not linked, we can just delete it right away from the database
                                        $this->orderProducts->forget($orderProductId);
                                        OrderProduct::where('id', $orderProductId)->delete();
                                    }
                                })
                                // Update totals summary after deleting an item
                                ->after(fn() => $this->dispatch('update-totals'))
                                ->modalCancelAction(fn(Action $action) => $action->extraAttributes(['class' => 'white'])),
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

    /**
     * Hydrate form state address fields from the quote's linked customers.
     */
    protected function hydrateAddressFormFromRecord(): void
    {
        $data = $this->data;
        $additional = $this->record->getAdditional() ?? [];
        $data['additional'] = $data['additional'] ?? [];
        $data['additional']['billing_name'] = $additional['billing_name'] ?? null;
        $data['additional']['shipping_name'] = $additional['shipping_name'] ?? null;
        $data['additional']['quote_comment'] = $additional['quote_comment'] ?? null;
        $data['additional']['delivery_time'] = $additional['delivery_time'] ?? null;
        $data['additional']['exact_payment_condition'] = $additional['exact_payment_condition'] ?? null;
        $data['additional']['exact_vat_code'] = $additional['exact_vat_code'] ?? null;

        $this->record->loadMissing([
            'billingCustomer.billingAddress',
            'shippingCustomer.shippingAddress',
            'shippingCustomer.billingAddress',
            'shippingCustomer.address',
        ]);

        $data['billing_customer_id'] = $this->record->billing_customer_id;
        $data['customer_id'] = $this->record->customer_id;
        $data['delivery_address_mode'] = $this->resolveDeliveryAddressModeForForm();

        if (($data['additional']['exact_payment_condition'] ?? '') === '' && $this->record->main_id !== null) {
            $this->record->loadMissing('main');
            $mainForCondition = $this->record->main;
            if ($mainForCondition !== null) {
                $data['additional']['exact_payment_condition'] = $mainForCondition->getExactPaymentConditionInheritedByChildren();
            }
        }
        if ($this->record->payment_terms === null && $this->record->main_id !== null) {
            $this->record->loadMissing('main');
            $mainForTerms = $this->record->main;
            if ($mainForTerms !== null) {
                $data['payment_terms'] = $mainForTerms->getPaymentTermsInheritedByChildren()->value;
            }
        }

        $billingCustomer = $this->record->billingCustomer;
        $billingForSnapshot = $billingCustomer;

        if (($data['additional']['exact_payment_condition'] ?? '') === '') {
            $data['additional']['exact_payment_condition'] = $this->record->resolveExactPaymentConditionCodeForBillingContext($billingCustomer);
        }

        if ($billingForSnapshot !== null) {
            $billingAddr = $billingForSnapshot->billingAddress;
            if (($data['additional']['billing_name'] ?? '') === '') {
                $data['additional']['billing_name'] = $this->lineTerAttentieVan($billingAddr, $billingForSnapshot);
            }
            if ($billingAddr !== null) {
                $data['additional']['invoice_address'] = $this->addressToQuoteSnapshot($billingAddr);
            }
        }

        if ($this->record->resolveShippingAddressTypeKey() === self::DELIVERY_ADDRESS_MODE_CUSTOM) {
            $delivery = $additional['delivery_address'] ?? null;
            if (is_array($delivery)) {
                $data['additional']['delivery_address'] = $delivery;
            }
            if (($data['additional']['shipping_name'] ?? '') === '' && filled($additional['shipping_name'] ?? null)) {
                $data['additional']['shipping_name'] = $additional['shipping_name'];
            }
        } else {
            $shippingCustomer = $this->record->shippingCustomer ?? $billingCustomer;
            if ($shippingCustomer !== null) {
                $shippingAddr = $this->resolveDeliveryStreetAddressForCustomer($shippingCustomer);
                if (($data['additional']['shipping_name'] ?? '') === '') {
                    $data['additional']['shipping_name'] = $this->lineTerAttentieVan($shippingAddr, $shippingCustomer);
                }
                if ($shippingAddr !== null) {
                    $data['additional']['delivery_address'] = $this->addressToQuoteSnapshot($shippingAddr);
                }
            }
        }

        $this->hydrateExactVatCodeFromBillingType($data);
        $data['vat_percentage'] = (string)$this->getVatPercentageFromCode($data['additional']['exact_vat_code'] ?? null);

        if ($this->record->main_id !== null) {
            $this->record->loadMissing('main');
            $main = $this->record->main;
            if ($main !== null) {
                $data['main_reference_sync']          = $main->getReference() ?? '';
                $data['main_reference_internal_sync'] = $main->getReferenceInternal() ?? '';
            }
        }

        $this->form->fill($data);
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

    protected function getCustomerOrDealerOptions(): array
    {
        $customers = Customer::query()
            ->active()
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where('type', '!=', CustomerType::Dealer->value)
            ->orderBy('name')
            ->limit(100)->get()
            ->mapWithKeys(fn(Customer $c): array => [$c->id => $c->getName()]);

        $dealers = Customer::query()
            ->active()
            ->where('type', CustomerType::Dealer->value)
            ->orderBy('name')
            ->limit(100)->get()
            ->mapWithKeys(fn(Customer $c): array => [$c->id => $c->getName()]);

        return $customers->all() + $dealers->all();
    }

    /**
     * Same as {@see getCustomerOrDealerOptions()} but always includes the quote’s current customer and billing
     * (and live form selections) so inactive customers, types outside the cap, etc. still validate and show a label.
     *
     * @return array<int, string>
     */
    protected function getCustomerOrDealerOptionsForForm(): array
    {
        $options = $this->getCustomerOrDealerOptions();
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
                $options[$id] = $customer->getName();
            }
        }

        ksort($options, SORT_NUMERIC);

        return $options;
    }

    protected function searchCustomerOrDealerOptions(string $search): array
    {
        $customers = Customer::query()
            ->active()
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where('type', '!=', CustomerType::Dealer->value)
            ->where(fn($q) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)->get()
            ->mapWithKeys(fn(Customer $c): array => [$c->id => $c->getName()]);

        $dealers = Customer::query()
            ->active()
            ->where('type', CustomerType::Dealer->value)
            ->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)->get()
            ->mapWithKeys(fn(Customer $c): array => [$c->id => $c->getName()]);

        return $customers->all() + $dealers->all();
    }

    /**
     * First line above invoice/delivery blocks: prefer {@see Address::getName()}, otherwise the customer display name.
     */
    protected function lineTerAttentieVan(?Address $address, ?Customer $customer): string
    {
        $fromAddress = trim((string) ($address?->getName() ?? ''));
        $fallback = trim((string) ($customer?->getName() ?? ''));
        $name = $fromAddress !== '' ? $fromAddress : $fallback;

        if ($name === '') {
            return '';
        }

        return 'Ter attentie van: '.$name;
    }

    /**
     * Street-level delivery snapshot for the quote UI: aligns with {@see Customer::getPhysicalDeliveryAddress()}
     * so billing-as-delivery ({@see Customer::$delivery_address_type} contact) uses billing, not an empty shipping row.
     */
    protected function resolveDeliveryStreetAddressForCustomer(Customer $customer): ?Address
    {
        $customer->loadMissing(['shippingAddress', 'billingAddress', 'address']);

        return $customer->getPhysicalDeliveryAddress();
    }

    /**
     * Quote form snapshot for an {@see Address} (invoice / delivery), including name and additional (e.g. location label).
     *
     * @return array<string, mixed>
     */
    private function addressToQuoteSnapshot(Address $addr): array
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

    protected function syncHeaderCustomerForQuote(Set $set, Get $get, mixed $state): void
    {
        $customerId = is_numeric($state) ? (int) $state : null;
        if ($customerId === null) {
            return;
        }

        $customer = Customer::query()->with(['billingAddress', 'shippingAddress', 'address'])->find($customerId);
        if ($customer === null) {
            return;
        }

        $set('customer_id', $customerId);

        $mode = $get('delivery_address_mode');
        $mode = is_string($mode) && $mode !== '' ? $mode : self::DELIVERY_ADDRESS_MODE_INVOICE;
        if ($mode === self::DELIVERY_ADDRESS_MODE_CUSTOMER) {
            $set('shipping_customer_id', $customerId);
            $this->syncShippingCustomer($set, $customerId);
        }

        $this->dispatch('update-totals');
    }

    protected function syncShippingCustomer(Set $set, mixed $state): void
    {
        $customerId = is_numeric($state) ? (int)$state : null;
        if ($customerId === null) {
            return;
        }

        $customer = Customer::query()->with(['shippingAddress', 'billingAddress', 'address'])->find($customerId);
        if ($customer === null) {
            return;
        }

        $address = $this->resolveDeliveryStreetAddressForCustomer($customer);
        if ($address !== null) {
            $set('additional.delivery_address', $this->addressToQuoteSnapshot($address));
        }

        $set('additional.shipping_name', $this->lineTerAttentieVan($address, $customer));
    }

    protected function syncBillingCustomer(Set $set, mixed $state): void
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
            $set('additional.invoice_address', $this->addressToQuoteSnapshot($billing));
        }

        $set('additional.billing_name', $this->lineTerAttentieVan($billing, $customer));
        $set('additional.exact_payment_condition', $this->record->resolveExactPaymentConditionCodeForBillingContext($customer));

        $vatCode = $customer->getExactVatCode();
        $set('additional.exact_vat_code', $vatCode);
        $set('vat_percentage', (string) $this->getVatPercentageFromCode($vatCode));

        $discount = (float) ($customer->discount_percentage ?? 0);
        $this->applyDealerDiscountToOrderProducts($discount);

        $endCustomerId = (int) ($this->data['customer_id'] ?? 0);
        $isSameAsEndCustomer = $endCustomerId !== 0 && $endCustomerId === $customerId;

        // Determine the new delivery mode based on the new billing customer.
        $newMode = $isSameAsEndCustomer
            ? self::DELIVERY_ADDRESS_MODE_CUSTOMER
            : ($customer->getType() === CustomerType::Dealer
                ? self::DELIVERY_ADDRESS_MODE_DEALER
                : ($this->data['delivery_address_mode'] ?? self::DELIVERY_ADDRESS_MODE_INVOICE));

        $set('delivery_address_mode', $newMode);

        // Resolve the shipping customer based on the new mode.
        $shippingCustomerId = ($newMode === self::DELIVERY_ADDRESS_MODE_CUSTOMER && $endCustomerId !== 0)
            ? $endCustomerId
            : $customerId;

        $set('shipping_customer_id', $shippingCustomerId);
        $this->syncShippingCustomer($set, $shippingCustomerId);

        $this->dispatch('update-totals');
    }

    /**
     * When form state has missing or empty invoice/delivery address, fill from record so save() never overwrites with empty data.
     */
    protected function ensureAddressDataFromRecord(array &$data): void
    {
        $additional = &$data['additional'];
        $invoice = $additional['invoice_address'] ?? null;
        if ($invoice === null || !$this->addressArrayHasContent($invoice)) {
            if ($this->record->billingAddress !== null) {
                $addr = $this->record->billingAddress;
                $additional['invoice_address'] = $this->addressToQuoteSnapshot($addr);
            } elseif (($data['billing_address_type'] ?? null) === 'customer' && $this->record->customer?->address !== null) {
                $addr = $this->record->customer->address;
                $additional['invoice_address'] = $this->addressToQuoteSnapshot($addr);
            }
        }
        $delivery = $additional['delivery_address'] ?? null;
        if ($delivery === null || ! $this->addressArrayHasContent($delivery)) {
            $this->record->loadMissing('shippingCustomer');
            $shipping = $this->record->shippingCustomer;
            $physical = $shipping !== null
                ? $this->resolveDeliveryStreetAddressForCustomer($shipping)
                : null;
            if ($physical !== null) {
                $addr = $physical;
                $additional['delivery_address'] = $this->addressToQuoteSnapshot($addr);
            } elseif ($this->record->shippingAddress !== null) {
                $addr = $this->record->shippingAddress;
                $additional['delivery_address'] = $this->addressToQuoteSnapshot($addr);
            }
        }
    }

    private function addressArrayHasContent(array $addr): bool
    {
        $v = ($addr['street'] ?? '') . ($addr['postcode'] ?? '') . ($addr['city'] ?? '');
        return $v !== '';
    }

    /**
     * Persist form state additional.invoice_address and additional.delivery_address to the quote's billing/shipping Address models.
     * Only overwrites address fields when the form has a value; keeps existing values to avoid clearing street/city when form state is partial.
     */
    /**
     * @param array<string, mixed>|null $invoiceSnapshot
     * @param array<string, mixed>|null $deliverySnapshot
     */
    protected function persistAddressFormToRecord(?array $invoiceSnapshot = null, ?array $deliverySnapshot = null): void
    {
        $data = $this->form->getState();
        $invoice = $invoiceSnapshot ?? ($data['additional']['invoice_address'] ?? null);
        if ($invoice !== null && $this->record->billingCustomer?->billingAddress !== null) {
            $addr = $this->record->billingCustomer->billingAddress;
            $update = array_merge(
                $addr->only(['street', 'house_number', 'house_number_addition', 'postcode', 'city', 'country_id', 'region_id', 'comment', 'additional', 'name', 'email']),
                array_filter($invoice, fn($v) => $v !== null && $v !== '')
            );
            $addr->update($update);
        }
        $delivery = $deliverySnapshot ?? ($data['additional']['delivery_address'] ?? null);
        if ($delivery !== null && $this->record->shippingCustomer?->shippingAddress !== null) {
            $addr = $this->record->shippingCustomer->shippingAddress;
            $update = array_merge(
                $addr->only(['street', 'house_number', 'house_number_addition', 'postcode', 'city', 'country_id', 'region_id', 'comment', 'additional', 'name', 'email']),
                array_filter($delivery, fn($v) => $v !== null && $v !== '')
            );
            $addr->update($update);
        }
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
        $rounded = (string)round($dealerDiscountPercentage, 2);

        foreach ($state as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $current = $item['company_sales_price_discount_percentage'] ?? null;
            $currentRounded = $current === null || $current === ''
                ? null
                : (string)round((float)$current, 2);

            if ($currentRounded === $rounded) {
                continue;
            }

            $state[$key]['company_sales_price_discount_percentage'] = $rounded;
            $hasUpdates = true;
        }

        if (!$hasUpdates) {
            return;
        }

        $repeater->state($state);
        $this->dispatch('update-totals');
    }

    private function getCurrentDealerDiscountPercentage(): float
    {
        return (float)($this->record->billingCustomer?->discount_percentage ?? 0);
    }
}

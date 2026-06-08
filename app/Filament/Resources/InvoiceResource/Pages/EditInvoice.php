<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Actions\SendInvoiceMailAction;
use App\Enums\OrderGeneralStatus;
use App\Filament\Actions\ImportBomAction;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Support\OrderProductRepeaterAddAction;
use App\Enums\PaymentTerms;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Concerns\ManagesRecordLock;
use App\Filament\Support\RecordLockEditPage;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Actions\SubmitInvoiceEmailAction;
use App\Jobs\SyncInvoiceToExactJob;
use App\Models\AppSyncMessage;
use App\Models\Document;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Enums\DeliveryTime;
use App\Enums\InvoiceCaption;
use App\Models\Order\Invoice;
use App\Models\Order\Main;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vrijstaande factuur (invoice zonder main/order). Alleen status initial tot verzenden.
 *
 * @property Invoice $record
 */
class EditInvoice extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;
    use ManagesRecordLock;

    protected static string $resource = InvoiceResource::class;

    protected string $view = RecordLockEditPage::VIEW;

    protected $listeners = [
        'addOrderProduct' => 'addOrderProduct',
        'loadBomProducts' => 'loadBomProducts',
    ];

    /** @var Collection<int, array>|null */
    public ?Collection $orderProducts = null;

    /** @var list<int> */
    public array $orderProductsToDelete = [];

    /**
     * @var string|null $companyPurchasePriceDiscount Field to hold the company purchase price discount (shared with totals.blade.php).
     */
    public ?string $companyPurchasePriceDiscount = null;

    /**
     * @var string|null $companySalesPriceDiscount Field to hold the company sales price discount (shared with totals.blade.php).
     */
    public ?string $companySalesPriceDiscount = null;

    protected function resolveRecord(int|string $key): Model
    {
        $invoice = Invoice::withoutGlobalScopes()->findOrFail($key);

        if (! InvoiceResource::isStandaloneInvoice($invoice)) {
            abort(403);
        }

        if ($invoice->getStatus() !== OrderGeneralStatus::Initial) {
            abort(403);
        }

        return $invoice;
    }

    protected function getFormModel(): string
    {
        return Invoice::class;
    }

    public function mount(int|string $record): void
    {
        if (! $this->mountRecordLockGate($record)) {
            return;
        }

        parent::mount($record);

        $this->completeRecordLockMount();

        $this->orderProducts ??= collect();
        $this->loadOrderProducts();
        $this->formatCompanyPurchasePriceDiscount();
        $this->formatCompanySalesPriceDiscount();
    }

    protected function getRedirectUrl(): string
    {
        return route('filament.app.resources.invoices.index');
    }

    protected function getBackToOverviewTitle(): string
    {
        return 'Facturen';
    }

    protected function getBackToOverviewUrl(): string
    {
        return route('filament.app.resources.invoices.index');
    }

    protected function isMainIdDisabled(): bool
    {
        return false;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $this->record->loadMissing(['customer', 'billingCustomer']);

        $data['additional'] = array_merge(
            [
                'exact_payment_condition' => '',
                'exact_vat_code' => '',
            ],
            $this->record->getAdditional() ?? []
        );

        $billingKey = $data['additional']['billing_address_type_key'] ?? null;
        if ($billingKey === null || $billingKey === '') {
            $billingCustomerId = $this->record->billing_customer_id;
            $billingKey = $billingCustomerId !== null
                ? 'customer-' . $billingCustomerId
                : 'customer';
            $data['additional']['billing_address_type_key'] = $billingKey;
        }
        $data['billing_address_type'] = $billingKey;

        $this->hydrateExactPaymentConditionFromBillingType($data);
        $this->hydrateExactVatCodeFromBillingType($data);

        $data['vat_percentage'] = (string) $this->getVatPercentageFromCode($data['additional']['exact_vat_code'] ?? null);

        $data['order_reference'] = $this->record->order_reference ?? '';

        $data['totals_summary'] = [];

        $data['billing_party_display'] = '_';

        return $data;
    }

    protected function getBillingPartyDisplayLabel(): string
    {
        $this->record->loadMissing(['customer', 'billingCustomer']);

        $billingCustomer = $this->record->billingCustomer;
        if ($billingCustomer !== null) {
            $name = trim((string) $billingCustomer->getName());

            return $name !== '' ? ($billingCustomer->getType()?->isBusiness() ? 'Dealer: ' : 'Klant: ') . $name : 'Klant #' . $billingCustomer->id;
        }

        $customer = $this->record->customer;
        if ($customer !== null) {
            $name = trim((string) $customer->getName());

            return $name !== '' ? 'Klant: ' . $name : 'Klant #' . $customer->id;
        }

        return '—';
    }

    protected function persistCommercialHeaderFromForm(): void
    {
        $data = $this->form->getState();

        if (array_key_exists('order_comment', $data)) {
            $comment = $data['order_comment'];
            $this->record->setOrderComment(
                is_string($comment) && trim($comment) !== '' ? $comment : null
            );
        }

        if (array_key_exists('order_reference', $data)) {
            $ref = trim((string) ($data['order_reference'] ?? ''));
            $this->record->order_reference = $ref !== '' ? $ref : null;
        }

        if (array_key_exists('caption', $data)) {
            $captionVal = $data['caption'];
            $this->record->caption = ($captionVal !== null && $captionVal !== '')
                ? InvoiceCaption::from($captionVal)
                : null;
        }

        if (array_key_exists('main_id', $data)) {
            $newMainId = $data['main_id'] ? (int) $data['main_id'] : null;
            $oldMainId = $this->record->main_id;

            $this->record->main_id = $newMainId;

            if ($newMainId !== null && $newMainId !== $oldMainId) {
                $main = Main::query()->find($newMainId);
                $main?->orderEvents()->create([
                    'type' => 'Factuur gekoppeld: #' . $this->record->getUidFormatted(),
                    'data' => [],
                    'user_id' => Auth::id(),
                ]);
            }
        }

        if (array_key_exists('payment_terms', $data)) {
            $val = $data['payment_terms'];
            $this->record->payment_terms = ($val !== null && $val !== '')
                ? PaymentTerms::from((string) $val)
                : null;
        }

        $merged = array_merge(
            $this->record->getAdditional() ?? [],
            is_array($data['additional'] ?? null) ? $data['additional'] : []
        );
        $this->record->setAdditional($merged);

        if ($this->record->author_id === null && auth()->id() !== null) {
            $this->record->author_id = auth()->id();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hydrateExactPaymentConditionFromBillingType(array &$data): void
    {
        $add = &$data['additional'];
        if (($add['exact_payment_condition'] ?? '') !== '') {
            return;
        }
        $billingCustomer = $this->record->billingCustomer ?? $this->record->customer;
        $add['exact_payment_condition'] = $this->record->resolveExactPaymentConditionCodeForBillingContext($billingCustomer);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hydrateExactVatCodeFromBillingType(array &$data): void
    {
        $add = &$data['additional'];
        if (($add['exact_vat_code'] ?? '') !== '') {
            return;
        }
        $billingCustomer = $this->record->billingCustomer ?? $this->record->customer;
        if ($billingCustomer !== null) {
            $add['exact_vat_code'] = $billingCustomer->getExactVatCode();
        }
    }

    public function getVatPercentageFromCode(?string $code): float
    {
        if ($code === null || $code === '') {
            return 21.0;
        }
        $vatCode = ExactVATCode::query()->where('code', $code)->first();
        if ($vatCode === null) {
            return 21.0;
        }
        $pct = (float) $vatCode->percentage;

        return $pct <= 1 ? $pct * 100 : $pct;
    }

    protected function getCurrentDealerDiscountPercentage(): float
    {
        return (float) ($this->record->billingCustomer?->discount_percentage ?? 0);
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            Notification::make()
                ->title('Formulier ongeldig')
                ->body('Controleer de verplichte velden.')
                ->warning()
                ->send();
            throw $e;
        }
    }

    public function applyFormAndSaveForPreview(): void
    {
        $this->persistInvoiceData(regenerateDocument: true);
        $this->record->refresh();
    }

    /**
     * @throws Throwable
     */
    public function persistInvoiceData(bool $regenerateDocument = true): void
    {
        $this->form->validate();

        $this->persistCommercialHeaderFromForm();

        $this->updateCompanyPurchasePriceDiscount((string) ($this->companyPurchasePriceDiscount ?? ''));
        $this->updateCompanySalesPriceDiscount((string) ($this->companySalesPriceDiscount ?? ''));
        $this->record->save();

        foreach ($this->orderProductsToDelete as $orderProductId) {
            OrderProduct::query()->whereKey($orderProductId)->delete();
        }
        $this->orderProductsToDelete = [];

        $rows = $this->data['order_products'] ?? [];
        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages(['order_products' => 'Voeg minstens één artikel toe.']);
        }

        $hasRealLine = false;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pid = $row['product_id'] ?? null;
            $oid = $row['id'] ?? 0;
            if ((int) $oid !== 0 && $pid) {
                $hasRealLine = true;
                break;
            }
        }
        if (! $hasRealLine) {
            throw ValidationException::withMessages(['order_products' => 'Voeg minstens één artikel toe.']);
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $opId = (int) ($row['id'] ?? 0);
            if ($opId === 0) {
                continue;
            }
            $op = OrderProduct::query()->find($opId);
            if ($op === null) {
                continue;
            }
            $qty = (float) ($row['qty'] ?? 1);
            if ($qty <= 0) {
                throw ValidationException::withMessages(['order_products' => 'Aantal moet groter dan 0 zijn.']);
            }

            $this->applyOrderProductSalesPricingFromFormRow($op, $row);
            $op->setOrderId($this->record->getId());
            $op->save();
        }

        $this->syncOrderProductSortFromFormState();

        $additional = $this->record->getAdditional() ?? [];
        $vatCode = $additional['exact_vat_code'] ?? null;
        if ($vatCode !== null && $vatCode !== '') {
            $vatPercent = $this->getVatPercentageFromCode($vatCode);
            OrderProduct::query()
                ->where('order_id', $this->record->getId())
                ->each(function (OrderProduct $line) use ($vatPercent): void {
                    $line->setVat($vatPercent);
                    $line->save();
                });
        }

        $this->record->refresh();

        $invoice = Invoice::withoutGlobalScopes()->findOrFail($this->record->getId());
        $invoice->setInitialPaymentAmount();
        $invoice->save();

        if ($invoice->getUid() === null || $invoice->getUid() === '') {
            $invoice->setUid($invoice->getNewUid());
            $invoice->save();
        }

        $this->record = $invoice;

        if ($regenerateDocument) {
            Document::query()
                ->where('documentable_id', $this->record->getId())
                ->where('documentable_type', $this->record->getMorphClass())
                ->delete();
            Document::createFromOrder($this->record);
        }
    }

    /**
     * @param  array{
     *     to: array<int, string>,
     *     cc: array<int, string>,
     *     bcc: array<int, string>,
     *     subject: string,
     *     message: string
     * }  $emailData
     *
     * @throws Throwable
     */
    public function submitWithEmail(array $emailData): void
    {
        try {
            $this->persistInvoiceData(regenerateDocument: true);
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Opslaan mislukt')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->record->refresh();
        $this->record->getOrCreatePublicDownloadUuid();
        $emailData = SubmitInvoiceEmailAction::applyTemplateVariablesAfterPersist($this->record, $emailData);

        $fresh = Invoice::withoutGlobalScopes()->findOrFail($this->record->getId());

        try {
            app()->makeWith(SendInvoiceMailAction::class, ['invoice' => $fresh])->executeWithModalEmail(
                to: $emailData['to'] ?? [],
                cc: $emailData['cc'] ?? [],
                bcc: $emailData['bcc'] ?? [],
                subject: (string) ($emailData['subject'] ?? ''),
                message: (string) ($emailData['message'] ?? ''),
            );
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Verzenden mislukt')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $fresh->setSentAt(now());
        $fresh->setStatus(OrderGeneralStatus::Sent);
        $fresh->save();

        $this->record = Invoice::withoutGlobalScopes()->findOrFail($fresh->getKey());

        if (config('exact.enabled')) {
            SyncInvoiceToExactJob::dispatch($fresh->getId(), Auth::id());
            AppSyncMessage::flashDeferredExactSyncToastPolling();
        }

        $toDisplay = is_array($emailData['to'] ?? null) ? implode(', ', $emailData['to']) : (string) ($emailData['to'] ?? '');
        Notification::make()
            ->title('Factuur verzonden')
            ->body("E-mail is verzonden naar: {$toDisplay}")
            ->success()
            ->send();

        $this->redirect($this->getRedirectUrl());
    }

    public function updateCompanyPurchasePriceDiscount(string $value): void
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || ! is_numeric($normalized)) {
            return;
        }

        $amount = round(abs((float) $normalized), 2);
        $this->companyPurchasePriceDiscount = number_format($amount, 2, ',', '');

        $this->record->setCompanyPurchasePriceDiscount(-$amount);
    }

    public function updateCompanySalesPriceDiscount(string $value): void
    {
        $normalized = str_replace(',', '.', trim($value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            $this->companySalesPriceDiscount = '0,00';
            $this->record->setCompanySalesPriceDiscount(0.0);

            return;
        }

        $amount = round(abs((float) $normalized), 2);
        $this->companySalesPriceDiscount = number_format($amount, 2, ',', '');
        $this->record->setCompanySalesPriceDiscount(-$amount);
    }

    protected function formatCompanyPurchasePriceDiscount(): void
    {
        $this->companyPurchasePriceDiscount = $this->record->getCompanyPurchasePriceDiscount() !== null
            ? number_format(abs((float) $this->record->getCompanyPurchasePriceDiscount()), 2, ',', '')
            : '0,00';
    }

    protected function formatCompanySalesPriceDiscount(): void
    {
        $this->companySalesPriceDiscount = $this->record->getCompanySalesPriceDiscount() !== null
            ? number_format(abs((float) $this->record->getCompanySalesPriceDiscount()), 2, ',', '')
            : '0,00';
    }

    public function addOrderProduct(array $data): void
    {
        $orderProductId = $data['orderProductId'] ?? null;
        if (! $orderProductId) {
            return;
        }

        $orderProduct = OrderProduct::query()->find($orderProductId);
        if (! $orderProduct instanceof OrderProduct) {
            return;
        }

        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();

        $orderProduct
            ->setCompanyPurchasePriceBase(round((float) $orderProduct->getCompanyPurchasePriceBase() + (float) $orderProduct->getCompanyPurchasePriceAdditional(), 2))
            ->setCompanyPurchasePriceAdditional(0)
            ->setCompanySalesPriceBase(round((float) $orderProduct->getCompanySalesPriceBase() + (float) $orderProduct->getCompanySalesPriceAdditional(), 2))
            ->setCompanySalesPriceAdditional(0)
            ->save();
        $orderProduct->refresh();

        $dealerDiscountPct = (string) round($this->getCurrentDealerDiscountPercentage(), 2);

        $discountPct = (float) ($orderProduct->getCompanySalesPriceDiscountPercentage() ?: $dealerDiscountPct);
        $base = (float) $orderProduct->getCompanySalesPriceBase();
        $qty = $this->qtyForOrderProductRepeaterRow($orderProduct);
        $subtotalBeforeDiscount = $base * $qty;
        $discount = $subtotalBeforeDiscount * ($discountPct / 100);
        $total = $subtotalBeforeDiscount - $discount;

        $state['record-'.$orderProduct->getId()] = [
            'id' => $orderProduct->getId(),
            'qty' => $this->qtyForOrderProductRepeaterRow($orderProduct),
            'product_allows_fractional_qty' => $this->productAllowsFractionalQtyForOrderProductRow($orderProduct),
            'product_id' => $orderProduct->getProductId(),
            'value' => $orderProduct->getValue(),
            'unit' => $orderProduct->product?->getUnit()?->getLabel() ?? '',
            'attribute_summary_basic' => $orderProduct->getAttributeSummaryBasic(),
            'company_purchase_price_base' => $this->formatLineItemAmount($orderProduct->getCompanyPurchasePriceBase()),
            'company_sales_price_base' => $this->salesPriceBaseForInput($base),
            'company_sales_price_qty_total' => $this->formatLineItemAmount($subtotalBeforeDiscount),
            'company_sales_price_discount_percentage' => (string) round($discountPct, 2),
            'company_sales_price_total' => $this->formatLineItemAmount($total),
            'supplier_id' => $orderProduct->getSupplierId(),
        ];

        $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());

        foreach ($state as $key => $item) {
            $isEmpty = ($item['id'] ?? 0) === 0 && empty($item['product_id']);
            $isDuplicate = ! str_starts_with((string) $key, 'record-') && ($item['id'] ?? 0) === $orderProduct->getId();
            if ($isEmpty || $isDuplicate) {
                unset($state[$key]);
            }
        }

        $repeater->state($state);
        $this->dispatch('update-totals');
    }

    public function loadBomProducts(?array $orderProducts): void
    {
        if (! is_array($orderProducts)) {
            return;
        }
        foreach ($orderProducts as $orderProductId) {
            $this->addOrderProduct(['orderProductId' => $orderProductId]);
        }
        $this->dispatch('update-totals');
    }

    public function loadOrderProduct(Get $get, Set $set): void
    {
        $product = Product::query()->find($get('product_id'));
        if (! $product instanceof Product) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $vatCode = $this->record->getAdditional()['exact_vat_code'] ?? null;
        $orderVatPercent = $this->getVatPercentageFromCode($vatCode);

        $attributeSummaryBasicFromProduct = $product->getDescription() ?? '';

        $salesPriceBase = round((float) ($product->getCompanySalesPrice() ?? 0), 2);
        $purchasePriceBase = round((float) $product->getCompanyPurchasePrice(), 2);
        $orderProductId = $get('id');

        if ($orderProductId && ($orderProduct = OrderProduct::query()->find($orderProductId))) {
            $qty = $this->normalizeQtyForProduct($product->getId(), $orderProduct->getQty() ?: 1);
            $orderProduct->update([
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => $qty,
                'company_purchase_price_base' => $purchasePriceBase ?: round((float) $orderProduct->getCompanyPurchasePriceBase(), 2),
                'company_purchase_price_additional' => 0,
                'company_sales_price_base' => $this->salesPriceBaseForDatabase(
                    $salesPriceBase ?: round((float) $orderProduct->getCompanySalesPriceBase(), 2)
                ),
                'company_sales_price_additional' => 0,
                'company_sales_price_subtotal' => $salesPriceBase,
                'company_sales_price_total' => $salesPriceBase * $qty,
                'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                'attribute_summary_company' => '',
                'attribute_summary' => '',
                'vat' => $orderVatPercent,
                'supplier_id' => $product->supplier?->getId(),
            ]);
        } else {
            $orderProduct = OrderProduct::query()->create([
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => 1,
                'company_purchase_price_base' => $purchasePriceBase,
                'company_purchase_price_additional' => 0,
                'company_purchase_price_subtotal' => $purchasePriceBase,
                'company_sales_price_base' => $this->salesPriceBaseForDatabase($salesPriceBase),
                'company_sales_price_additional' => 0,
                'company_sales_price_subtotal' => $salesPriceBase,
                'company_sales_price_total' => $salesPriceBase,
                'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                'vat' => $orderVatPercent,
                'supplier_id' => $product->supplier?->getId(),
                'order_id' => null,
            ]);
            $orderProduct->setFulfillmentTypeBasedOnProduct();
            $orderProduct->save();

            $set('id', $orderProduct->getId());
        }

        $orderProduct->refresh();
        $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());

        $set('qty', $this->qtyForOrderProductRepeaterRow($orderProduct));
        $set('value', $orderProduct->getValue());
        $set('unit', $product->getUnit()?->getLabel() ?? '');
        $set('attribute_summary_basic', $attributeSummaryBasicFromProduct);

        $set('company_purchase_price_base', $this->formatLineItemAmount($orderProduct->getCompanyPurchasePriceBase()));
        $set('company_sales_price_base', $this->salesPriceBaseForInput($orderProduct->getCompanySalesPriceBase()));

        $dealerPct = (string) round($this->getCurrentDealerDiscountPercentage(), 2);
        $set('company_sales_price_discount_percentage', $dealerPct);
        $loadBase = (float) $orderProduct->getCompanySalesPriceBase();
        $loadDiscountPct = (float) $dealerPct;
        $loadQty = $this->qtyForOrderProductRepeaterRow($orderProduct);
        $loadSubtotal = $loadBase * $loadQty;
        $loadDiscount = $loadSubtotal * ($loadDiscountPct / 100);
        $set('company_sales_price_qty_total', $this->formatLineItemAmount($loadSubtotal));
        $set('company_sales_price_total', $this->formatLineItemAmount($loadSubtotal - $loadDiscount));

        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();
        $repeater->state(array_filter($state, fn ($item): bool => (int) ($item['id'] ?? 0) !== 0));

        $this->dispatch('update-totals');
    }

    public function loadOrderProducts(): void
    {
        foreach ($this->record->orderProducts as $orderProduct) {
            $this->addOrderProduct([
                'orderProductId' => $orderProduct->getId(),
            ]);
        }

        if ($this->orderProducts->isEmpty()) {
            $repeater = $this->form->getComponent('order_products');
            $repeater->state([[
                'qty' => 1,
                'id' => 0,
                'product_id' => null,
                'attribute_summary_basic' => '',
                'company_purchase_price_base' => '0.00',
                'company_sales_price_base' => $this->salesPriceBaseForInput(0),
                'company_sales_price_qty_total' => '0,00',
                'company_sales_price_discount_percentage' => '0.00',
                'company_sales_price_total' => '0.00',
                'supplier_id' => null,
            ]]);
        }
    }

    public function form(Schema $schema): Schema
    {
        $title = 'Factuur aanmaken';
        if ($this->record->getUid()) {
            $title = 'Factuur: #'.$this->record->getUidFormatted();
        }

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => $this->getBackToOverviewTitle(),
                        'url' => $this->getBackToOverviewUrl(),
                    ]),

                Section::make($title)
                    ->columns(12)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Group::make()
                            ->columnSpan(6)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                Select::make('billing_party_display')
                                    ->label('Klant/dealer')
                                    ->inlineLabel()
                                    ->options(fn (): array => ['_' => $this->getBillingPartyDisplayLabel()])
                                    ->default('_')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->selectablePlaceholder(false)
                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap ter-attentie-van-field'])
                                    ->columnSpanFull(),
                            ]),

                        Grid::make(3)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'borderTop custom-form-design'])
                            ->schema([
                                // Links: Type factuur, Uw referentie, Aanvraag
                                Group::make()
                                    ->schema([
                                        Select::make('caption')
                                            ->label('Type factuur')
                                            ->required()
                                            ->inlineLabel()
                                            ->options(InvoiceCaption::formOptions())
                                            ->default(InvoiceCaption::RegularInvoice->value),

                                        TextInput::make('order_reference')
                                            ->label('Uw referentie (klant)')
                                            ->inlineLabel()
                                            ->required(),

                                        Select::make('main_id')
                                            ->label('Aanvraag')
                                            ->inlineLabel()
                                            ->placeholder('Geen aanvraag')
                                            ->options(function (): array {
                                                $customerId = $this->record->customer_id;
                                                $billingCustomerId = $this->record->billing_customer_id;

                                                return Main::query()
                                                    ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
                                                    ->when(! $customerId && $billingCustomerId, fn ($q) => $q->where('billing_customer_id', $billingCustomerId))
                                                    ->get()
                                                    ->mapWithKeys(fn (Main $m): array => [$m->getId() => $m->getUidFormatted()])
                                                    ->toArray();
                                            })
                                            ->searchable()
                                            ->nullable()
                                            ->disabled($this->isMainIdDisabled())
                                            ->dehydrated(! $this->isMainIdDisabled()),

                                        Hidden::make('vat_percentage')
                                            ->statePath('vat_percentage')
                                            ->default('21')
                                            ->dehydrated(false),
                                    ]),

                                // Midden: Verkoper, Opmerking, Levertijd
                                Group::make()
                                    ->schema([
                                        TextInput::make('seller')
                                            ->statePath('seller')
                                            ->label('Verkoper')
                                            ->inlineLabel()
                                            ->formatStateUsing(fn () => auth()?->user()?->getName() ?? '-')
                                            ->disabled()
                                            ->dehydrated(false),

                                        TextInput::make('order_comment')
                                            ->label('Opmerking')
                                            ->inlineLabel()
                                            ->maxLength(40),

                                        Select::make('additional.delivery_time')
                                            ->statePath('additional.delivery_time')
                                            ->label('Levertijd')
                                            ->inlineLabel()
                                            ->options(DeliveryTime::options())
                                            ->placeholder('-'),
                                    ]),

                                Group::make()
                                    ->schema([
                                        Select::make('payment_terms')
                                            ->label('Betalingsvoorwaarden')
                                            ->inlineLabel()
                                            ->options(PaymentTerms::labels())
                                            ->default(PaymentTerms::Postpay->value)
                                            ->nullable(),

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
                                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                $set('vat_percentage', (string) $this->getVatPercentageFromCode($state));
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
                                TableColumn::make('Korting'),
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
                                    ->live()
                                    ->extraFieldWrapperAttributes(['class' => 'input-unit']),

                                $this->configureOrderRepeaterProductSelect(ProductSelect::make('product_id'))
                                    ->required()
                                    ->extraAttributes(['class' => 'input-value'])
                                    ->extraFieldWrapperAttributes(['class' => 'product-select'])
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        $this->loadOrderProduct($get, $set);
                                    }),

                                Textarea::make('attribute_summary_basic')
                                    ->label('Specificaties')
                                    ->rows(3)
                                    ->formatStateUsing(fn ($state): string => arrayToTextareaString($state ?? []))
                                    ->extraFieldWrapperAttributes(['class' => 'input-specifications'])
                                    ->columnSpanFull(),

                                TextInput::make('company_purchase_price_base')
                                    ->label(new HtmlString('<span>Inkoop</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->readOnly()
                                    ->formatStateUsing(fn ($state) => $this->formatLineItemAmount($state))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                $this->companySalesPriceBaseRepeaterField(),

                                TextInput::make('company_sales_price_qty_total')
                                    ->label(new HtmlString('<span>Verkoop totaal</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->formatStateUsing(fn ($state, Get $get) => $this->formatLineItemAmount(
                                        $this->parseLineItemAmount($get('company_sales_price_base')) * ((float) ($get('qty') ?? 1) ?: 1)
                                    ))
                                    ->dehydrated(false)
                                    ->disabled()
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell']),

                                TextInput::make('company_sales_price_discount_percentage')
                                    ->label('Korting')
                                    ->suffix('%')
                                    ->numeric()
                                    ->default(fn (): string => (string) round((float) ($this->record->billingCustomer?->discount_percentage ?? 0), 2))
                                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2, '.', ''))
                                    ->afterStateUpdatedJs($this->updatePricesJs())
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell input-korting-pct']),

                                TextInput::make('company_sales_price_total')
                                    ->label(new HtmlString('<span>Nettoprijs</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->formatStateUsing(fn ($state) => $this->formatLineItemAmount($state))
                                    ->belowContent(
                                        Text::make($this->calculateCompanyMarginTotalJs())
                                            ->js()
                                            ->extraAttributes(['style' => 'white-space: pre-line'])
                                    )
                                    ->disabled()
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell']),
                            ])
                            ->addAction(fn (Action $action) => OrderProductRepeaterAddAction::configure($action))
                            ->deleteAction(fn (Action $action) => $action
                                ->label('Product verwijderen')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->size(Size::ExtraSmall)
                                ->requiresConfirmation()
                                ->before(function (array $arguments, Repeater $component, mixed $state): void {
                                    $item = $state[$arguments['item']] ?? [];
                                    $orderProductId = (int) ($item['id'] ?? 0);
                                    if ($orderProductId === 0) {
                                        return;
                                    }
                                    if ($this->orderProducts->get($orderProductId)['order_id'] ?? false) {
                                        $this->orderProductsToDelete[] = $orderProductId;
                                        $this->orderProducts->forget($orderProductId);
                                    } else {
                                        $this->orderProducts->forget($orderProductId);
                                        OrderProduct::query()->whereKey($orderProductId)->delete();
                                    }
                                })
                                ->after(fn () => $this->dispatch('update-totals'))
                                ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white'])))
                            ->columnSpanFull(),

                        Section::make('Samenvatting')
                            ->columnSpanFull()
                            ->schema([
                                View::make('filament.resources.quote-resource.totals')
                                    ->viewData(['showCompanySalesPrice' => true])
                                    ->statePath('totals_summary')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    protected function getFormActions(): array
    {
        return $this->formActionsUnlessRecordLockBlocked([
            Action::make('preview_invoice')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->extraAttributes(['class' => 'secondary'])
                ->mountUsing(function (): void {
                    $this->applyFormAndSaveForPreview();
                })
                ->modalHeading('Preview')
                ->modalContent(function (): HtmlString {
                    $this->record->refresh();
                    $this->record->loadMissing([
                        'orderProducts.product',
                        'billingCustomer',
                    ]);
                    $html = Document::buildHtmlSnapshotForOrder($this->record, isPreview: true);
                    $modalPaddingStyle = '<style>div.order-wrapper { padding: 10px !important; }</style>';
                    $html = str_replace('<head>', '<head>'.$modalPaddingStyle, $html);
                    $iframeId = 'invoice-preview-iframe';

                    return new HtmlString(
                        '<div style="border-radius:5px; max-height:75vh; overflow:hidden;">'
                        .'<iframe id="'.$iframeId.'" '
                        .'style="border:0; width:100%; height:75vh; border-radius:5px; display:block;" '
                        .'srcdoc="'.htmlspecialchars($html, ENT_QUOTES).'" '
                        .'sandbox="allow-same-origin allow-scripts allow-forms allow-modals" '
                        .'></iframe>'
                        .'</div>'
                    );
                })
                ->modalFooterActions([]),

            SubmitInvoiceEmailAction::make(),

            Action::make('cancel')
                ->label('Annuleren')
                ->color('gray')
                ->extraAttributes(['class' => 'white'])
                ->url($this->getRedirectUrl()),
        ]);
    }
}

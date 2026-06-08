<?php

namespace App\Filament\Resources\RecurringInvoices\Pages;

use App\Enums\PaymentTerms;
use App\Enums\RecurringInvoiceFrequency;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Support\OrderProductRepeaterAddAction;
use App\Filament\Resources\RecurringInvoices\Actions\ConfigureRecurringInvoiceEmailAction;
use App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Models\Customer;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Models\Product;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceLine;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Recurring invoice template page using the same line-item editor as a standalone invoice.
 *
 * @property RecurringInvoice $record
 */
class EditRecurringInvoice extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;

    protected static string $resource = RecurringInvoiceResource::class;

    protected static ?string $title = 'Abonnement bewerken';

    protected static ?string $breadcrumb = 'Abonnement bewerken';

    protected $listeners = [
        'addOrderProduct' => 'addRecurringLineProduct',
        'loadBomProducts' => 'loadBomProducts',
    ];

    /** @var Collection<int, array>|null */
    public ?Collection $recurringLines = null;

    /**
     * @var string|null $companyPurchasePriceDiscount Field to hold the company purchase price discount (shared with totals.blade.php).
     */
    public ?string $companyPurchasePriceDiscount = null;

    /**
     * @var string|null $companySalesPriceDiscount Field to hold the company sales price discount (shared with totals.blade.php).
     */
    public ?string $companySalesPriceDiscount = null;

    protected function getFormModel(): string
    {
        return RecurringInvoice::class;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->recurringLines ??= collect();
        $this->loadRecurringLineProducts();
        $this->companyPurchasePriceDiscount = '0,00';
        $this->companySalesPriceDiscount = '0,00';
    }

    protected function getRedirectUrl(): string
    {
        return route('filament.app.resources.recurring-invoices.index');
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        $parent = parent::getExtraBodyAttributes();
        $classes = array_filter(preg_split('/\s+/', (string) ($parent['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $classes[] = 'recurring-invoice-edit';

        return array_merge($parent, [
            'class' => implode(' ', array_unique($classes)),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $this->record->loadMissing(['billingCustomer', 'author']);

        $data['additional'] = array_merge(
            [
                'exact_payment_condition' => '',
                'exact_vat_code' => '',
            ],
            $this->record->additional ?? []
        );

        if (($data['additional']['exact_vat_code'] ?? '') === '' && $this->record->exact_vat_code !== '') {
            $data['additional']['exact_vat_code'] = $this->record->exact_vat_code;
        }
        if (($data['additional']['exact_payment_condition'] ?? '') === '' && $this->record->exact_payment_condition !== '') {
            $data['additional']['exact_payment_condition'] = $this->record->exact_payment_condition;
        }

        $this->hydrateExactPaymentConditionFromBillingType($data);
        $this->hydrateExactVatCodeFromBillingType($data);

        $data['vat_percentage'] = (string) $this->getVatPercentageFromCode($data['additional']['exact_vat_code'] ?? null);

        $data['name'] = $this->record->name ?? '';
        $data['order_comment'] = $this->record->comments ?? '';

        $data['totals_summary'] = [];

        $data['billing_party_display'] = '_';

        $data['is_active'] = $this->record->is_active ? '1' : '0';
        $data['frequency'] = $this->record->frequency instanceof RecurringInvoiceFrequency
            ? $this->record->frequency->value
            : (string) $this->record->frequency;
        $data['start_day'] = (string) max(1, min(31, (int) $this->record->start_day));

        return $data;
    }

    protected function getBillingPartyDisplayLabel(): string
    {
        $this->record->loadMissing(['billingCustomer']);

        $customerId = $this->record->billing_customer_id;
        if ($customerId !== null) {
            $customer = $this->record->billingCustomer;
            if ($customer !== null) {
                $name = trim((string) $customer->getName());

                return $name !== '' ? $name : 'Klant #'.$customerId;
            }

            return 'Klant #'.$customerId.' (ontbrekend)';
        }

        return '—';
    }

    protected function persistCommercialHeaderFromForm(): void
    {
        $data = $this->form->getState();

        if (array_key_exists('order_comment', $data)) {
            $comment = $data['order_comment'];
            $this->record->comments = is_string($comment) && trim($comment) !== ''
                ? $comment
                : null;
        }

        if (array_key_exists('name', $data)) {
            $this->record->setName(is_string($data['name'] ?? null) ? $data['name'] : null);
        }

        if (array_key_exists('is_active', $data)) {
            $raw = $data['is_active'];
            $this->record->is_active = $raw === '1' || $raw === 1 || $raw === true;
        }

        if (array_key_exists('frequency', $data)) {
            $frequency = RecurringInvoiceFrequency::tryFrom((string) $data['frequency']);
            if ($frequency !== null) {
                $this->record->frequency = $frequency;
            }
        }

        if (array_key_exists('start_day', $data)) {
            $this->record->start_day = max(1, min(31, (int) $data['start_day']));
        }

        $this->record->payment_terms = PaymentTerms::Postpay;

        if ($this->record->billing_customer_id !== null && ($this->record->billing_address_type === null || $this->record->billing_address_type === '')) {
            $this->record->billing_address_type = 'customer-' . $this->record->billing_customer_id;
        }

        $merged = array_merge(
            $this->record->additional ?? [],
            is_array($data['additional'] ?? null) ? $data['additional'] : []
        );
        $this->record->additional = $merged;
        $this->record->exact_vat_code = (string) ($merged['exact_vat_code'] ?? '');
        $this->record->exact_payment_condition = (string) ($merged['exact_payment_condition'] ?? '');

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
        $billingCustomer = $this->record->billingCustomer;
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
        if ($this->record->billingCustomer !== null) {
            $add['exact_vat_code'] = $this->record->billingCustomer->getExactVatCode();
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
        $this->record->loadMissing('billingCustomer');

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

    public function persistRecurringData(): void
    {
        $this->form->validate();

        $this->persistCommercialHeaderFromForm();
        $this->record->save();

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
            $lineId = (int) ($row['id'] ?? 0);
            if ($lineId === 0) {
                continue;
            }
            $line = RecurringInvoiceLine::query()->find($lineId);
            if ($line === null || $line->recurring_invoice_id !== $this->record->id) {
                continue;
            }
            $qty = (float) ($row['qty'] ?? 1);
            if ($qty <= 0) {
                throw ValidationException::withMessages(['order_products' => 'Aantal moet groter dan 0 zijn.']);
            }

            $this->applyRecurringLineSalesPricingFromFormRow($line, $row);
            $line->save();
        }

        $this->syncRecurringInvoiceLineSortFromFormState();

        $this->record->refresh();

        $this->syncUnsavedChangesAlertAfterCustomSave();

        Notification::make()
            ->title('Opgeslagen')
            ->success()
            ->send();
    }

    protected function syncUnsavedChangesAlertAfterCustomSave(): void
    {
        if (! method_exists($this, 'rememberData')) {
            return;
        }

        if (isset($this->record)) {
            $this->record->refresh();
        }

        $this->resyncRecurringInvoiceLinesStateFromRecordAfterSave();
        $this->baselineFormStateForUnsavedChangesAlert();
        $this->rememberData();
    }

    protected function resyncRecurringInvoiceLinesStateFromRecordAfterSave(): void
    {
        if (! isset($this->record, $this->form)) {
            return;
        }

        $this->recurringLines = collect();

        try {
            $this->form->getComponent('order_products')->state([]);
        } catch (\Throwable) {
            return;
        }

        $this->loadRecurringLineProducts();
    }

    public function addRecurringLineProduct(array $data): void
    {
        $lineId = $data['orderProductId'] ?? null;
        if (! $lineId) {
            return;
        }

        $line = RecurringInvoiceLine::query()
            ->where('recurring_invoice_id', $this->record->id)
            ->find($lineId);
        if (! $line instanceof RecurringInvoiceLine) {
            return;
        }

        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();

        $dealerDiscountPct = (string) round($this->getCurrentDealerDiscountPercentage(), 2);

        $discountPct = (float) ($line->company_sales_price_discount_percentage ?: $dealerDiscountPct);
        $base = (float) $line->company_sales_price_base;
        $qty = $this->normalizeQtyForProduct($line->product_id, $line->qty ?? 1);
        $subtotalBeforeDiscount = $base * $qty;
        $discount = $subtotalBeforeDiscount * ($discountPct / 100);
        $total = $subtotalBeforeDiscount - $discount;

        $state['record-'.$line->id] = [
            'id' => $line->id,
            'qty' => $qty,
            'product_allows_fractional_qty' => $this->productAllowsFractionalQty($line->product_id),
            'product_id' => $line->product_id,
            'value' => $line->value,
            'unit' => $line->product?->getUnit()?->getLabel() ?? '',
            'attribute_summary_basic' => $line->attribute_summary_basic,
            'company_purchase_price_base' => $this->formatLineItemAmount($line->company_purchase_price_base),
            'company_sales_price_base' => $this->salesPriceBaseForInput($base),
            'company_sales_price_qty_total' => $this->formatLineItemAmount($subtotalBeforeDiscount),
            'company_sales_price_discount_percentage' => (string) round($discountPct, 2),
            'company_sales_price_total' => $this->formatLineItemAmount($total),
            'supplier_id' => $line->supplier_id,
        ];

        $this->recurringLines->put($line->id, $line->toArray());

        foreach ($state as $key => $item) {
            $isEmpty = ($item['id'] ?? 0) === 0 && empty($item['product_id']);
            $isDuplicate = ! str_starts_with((string) $key, 'record-') && ($item['id'] ?? 0) === $line->id;
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
            $this->addRecurringLineProduct(['orderProductId' => $orderProductId]);
        }
        $this->dispatch('update-totals');
    }

    public function loadRecurringLineProduct(Get $get, Set $set): void
    {
        $product = Product::query()->find($get('product_id'));
        if (! $product instanceof Product) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $attributeSummaryBasicFromProduct = $product->getDescription() ?? '';

        $salesPriceBase = round((float) ($product->getCompanySalesPrice() ?? 0), 2);
        $purchasePriceBase = round((float) $product->getCompanyPurchasePrice(), 2);
        $lineId = $get('id');

        if ($lineId && ($line = RecurringInvoiceLine::query()
            ->where('recurring_invoice_id', $this->record->id)
            ->find($lineId))) {
            $qty = $this->normalizeQtyForProduct($product->getId(), $line->qty ?: 1);
            $line->update([
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => $qty,
                'company_purchase_price_base' => (string) ($purchasePriceBase ?: round((float) $line->company_purchase_price_base, 2)),
                'company_sales_price_base' => $this->salesPriceBaseForDatabase(
                    $salesPriceBase ?: round((float) $line->company_sales_price_base, 2)
                ),
                'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                'supplier_id' => $product->supplier?->getId(),
            ]);
        } else {
            $dealerPctNew = (string) round($this->getCurrentDealerDiscountPercentage(), 2);
            $line = RecurringInvoiceLine::query()->create([
                'recurring_invoice_id' => $this->record->id,
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => '1',
                'company_purchase_price_base' => (string) $purchasePriceBase,
                'company_sales_price_base' => $this->salesPriceBaseForDatabase($salesPriceBase),
                'company_sales_price_discount_percentage' => $dealerPctNew,
                'attribute_summary_basic' => $attributeSummaryBasicFromProduct,
                'supplier_id' => $product->supplier?->getId(),
            ]);

            $set('id', $line->id);
        }

        $line->refresh();
        $this->recurringLines->put($line->id, $line->toArray());

        $set('qty', $this->normalizeQtyForProduct($product->getId(), $line->qty));
        $set('value', $line->value);
        $set('unit', $product->getUnit()?->getLabel() ?? '');
        $set('attribute_summary_basic', $attributeSummaryBasicFromProduct);

        $set('company_purchase_price_base', $this->formatLineItemAmount($line->company_purchase_price_base));
        $set('company_sales_price_base', $this->salesPriceBaseForInput($line->company_sales_price_base));

        $dealerPct = (string) round($this->getCurrentDealerDiscountPercentage(), 2);
        $set('company_sales_price_discount_percentage', $dealerPct);
        $loadBase = (float) $line->company_sales_price_base;
        $loadDiscountPct = (float) $dealerPct;
        $loadQty = $this->normalizeQtyForProduct($product->getId(), $line->qty ?: 1);
        $loadSubtotal = $loadBase * $loadQty;
        $loadDiscount = $loadSubtotal * ($loadDiscountPct / 100);
        $set('company_sales_price_qty_total', $this->formatLineItemAmount($loadSubtotal));
        $set('company_sales_price_total', $this->formatLineItemAmount($loadSubtotal - $loadDiscount));

        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();
        $repeater->state(array_filter($state, fn ($item): bool => (int) ($item['id'] ?? 0) !== 0));

        $this->dispatch('update-totals');
    }

    public function loadRecurringLineProducts(): void
    {
        $this->record->loadMissing(['lines.product']);

        foreach ($this->record->lines as $line) {
            $this->addRecurringLineProduct([
                'orderProductId' => $line->id,
            ]);
        }

        if ($this->recurringLines->isEmpty()) {
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

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function form(Schema $schema): Schema
    {
        $displayName = $this->record->getName();
        $title = $displayName !== null && $displayName !== ''
            ? $displayName
            : 'Abonnement #'.$this->record->id;

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Abonnementen',
                        'url' => route('filament.app.resources.recurring-invoices.index'),
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
                                Group::make()
                                    ->schema([
                                        Select::make('is_active')
                                            ->label('Ingeschakeld')
                                            ->inlineLabel()
                                            ->options([
                                                '1' => 'Ja',
                                                '0' => 'Nee',
                                            ])
                                            ->required()
                                            ->selectablePlaceholder(false)
                                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap']),

                                        Select::make('frequency')
                                            ->label('Frequentie')
                                            ->inlineLabel()
                                            ->options(RecurringInvoiceFrequency::options())
                                            ->required()
                                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap']),

                                        Select::make('start_day')
                                            ->label('Factuurdag')
                                            ->inlineLabel()
                                            ->options(collect(range(1, 31))->mapWithKeys(fn (int $day): array => [
                                                (string) $day => (string) $day,
                                            ])->all())
                                            ->required()
                                            ->selectablePlaceholder(false)
                                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap']),
                                    ]),

                                Group::make()
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Naam (intern)')
                                            ->inlineLabel()
                                            ->required()
                                            ->maxLength(255),

                                        TextInput::make('seller')
                                            ->statePath('seller')
                                            ->label('Verkoper')
                                            ->inlineLabel()
                                            ->formatStateUsing(fn (): string => $this->record->author?->getName()
                                                ?? auth()?->user()?->getName()
                                                ?? '-')
                                            ->disabled()
                                            ->dehydrated(false),

                                        TextInput::make('order_comment')
                                            ->label('Opmerking')
                                            ->inlineLabel(),

                                        Hidden::make('vat_percentage')
                                            ->statePath('vat_percentage')
                                            ->default('21')
                                            ->dehydrated(false),
                                    ]),

                                Group::make()
                                    ->schema([
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
                                        $this->loadRecurringLineProduct($get, $set);
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
                                    ->default(fn (): string => (string) round($this->getCurrentDealerDiscountPercentage(), 2))
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
                                    $lineId = (int) ($item['id'] ?? 0);
                                    if ($lineId === 0) {
                                        return;
                                    }
                                    $this->recurringLines->forget($lineId);
                                    RecurringInvoiceLine::query()
                                        ->where('recurring_invoice_id', $this->record->id)
                                        ->whereKey($lineId)
                                        ->delete();
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
        return [
            Html::make('<div class="editproduct-footer-actions">'),
            Html::make('<div>'),
            Action::make('save')
                ->label('Opslaan')
                ->action(function (): void {
                    try {
                        $this->persistRecurringData();
                    } catch (ValidationException $e) {
                        $this->dispatch('scrollToFirstError');
                        throw $e;
                    }
                }),
            Action::make('cancel')
                ->label('Annuleren')
                ->color('gray')
                ->extraAttributes(['class' => 'white'])
                ->url($this->getRedirectUrl()),
            Html::make('</div>'),
            Html::make('<div>'),
            ConfigureRecurringInvoiceEmailAction::make(),
            DeleteAction::make('delete')
                ->record($this->record)
                ->requiresConfirmation()
                ->label('Verwijderen')
                ->extraAttributes(['class' => 'white color-red-delete'])
                ->successRedirectUrl(RecurringInvoiceResource::getUrl('index')),
            Html::make('</div>'),
            Html::make('</div>'),
        ];
    }
}

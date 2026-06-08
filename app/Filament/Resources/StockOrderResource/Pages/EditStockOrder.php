<?php

namespace App\Filament\Resources\StockOrderResource\Pages;

use App\Actions\SendPurchaseOrderConfirmMailAction;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Concerns\ManagesRecordLock;
use App\Filament\Support\RecordLockEditPage;
use App\Filament\Forms\AddressFormSchema;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Support\OrderProductRepeaterAddAction;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\StockOrderResource\Actions\ApproveStockOrderEmailAction;
use App\Filament\Resources\StockOrderResource\Actions\PreviewStockOrderAction;
use App\Filament\Resources\StockOrderResource;
use App\Models\Customer;
use App\Models\Country;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
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
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;


/**
 * @property StockOrder $record
 */
class EditStockOrder extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;
    use ManagesRecordLock;
    use PostcodeValidatorTrait;

    protected static string $resource = StockOrderResource::class;

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
     * @var Collection<int, OrderProduct> $orderProducts Collection to hold the order products in the form.
     */
    public ?Collection $orderProducts = null;

    /**
     * @var int[] $orderProductsToDelete Array to hold the IDs of order products that should be deleted when saving the quote.
     */
    public array $orderProductsToDelete = [];
    /** @var array<int, \Illuminate\Http\UploadedFile> */
    public array $documentFiles = [];


    public function mount(int|string $record): void
    {
        if (! $this->mountRecordLockGate($record)) {
            return;
        }

        parent::mount($record);

        $this->completeRecordLockMount();

        $this->orderProducts ??= collect();

        //$this->record->with(['company']);
        $this->fillForm();

        $this->loadOrderProducts();
        $this->hydrateDeliveryAddressFormFromRecord();
        $this->formatCompanyPurchasePriceDiscount();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $typeKey = $data['shipping_address_type'] ?? null;
        $typeKeyStr = is_string($typeKey) ? $typeKey : (string) ($typeKey ?? '');

        if ($typeKeyStr === 'custom') {
            $additional = array_merge($this->record->getAdditional() ?? [], [
                'shipping_address_type_key' => 'custom',
                'delivery_address' => $data['additional']['delivery_address'] ?? null,
                'shipping_name' => $data['additional']['shipping_name'] ?? null,
            ]);
        } else {
            $key = $typeKeyStr !== '' ? $typeKeyStr : 'rd';
            $resolved = $this->getDeliveryAddressForTypeKey($key);
            $additional = array_merge($this->record->getAdditional() ?? [], [
                'shipping_address_type_key' => $key,
                'delivery_address' => $resolved['address'],
                'shipping_name' => $resolved['name'],
            ]);
        }

        $data['additional'] = $additional;
        unset($data['shipping_address_type']);

        return $data;
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

    protected function resolveRecord($key): StockOrder
    {
        return StockOrder::findOrFail($key);
    }

    public function updateCompanyPurchasePriceDiscount($value): void
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return;
        }

        $amount = round(abs((float) $normalized), 2);
        $this->companyPurchasePriceDiscount = number_format($amount, 2, ',', '');

        $this->record->setCompanyPurchasePriceDiscount(-$amount);
    }

    /**
     * Persist form state before opening preview. Uses the normal save path so
     * mutateFormDataBeforeSave() can map shipping_address_type keys (e.g. rd) to additional data.
     */
    public function applyFormAndSaveForPreview(): void
    {
        $this->save(false, false);
        $this->record->refresh();
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            // Set discounts
            $this->updateCompanyPurchasePriceDiscount($this->companyPurchasePriceDiscount);
            // Save record
            parent::save();

            $this->record->setAdditional($this->data['additional'] ?? []);
            $this->record->save();

            // Save order products — only persist user-editable fields to avoid
            // overwriting base/subtotal/total with stale form state values.
            $editableKeys = ['qty'];
            foreach ($this->orderProducts as $orderProduct) {
                $formData = array_find($this->data['order_products'], fn ($op) => $op['id'] == $orderProduct['id']) ?? [];

                /** @var OrderProduct $op */
                $op = OrderProduct::find($orderProduct['id']);
                if (! empty($formData)) {
                    $op->update(array_intersect_key($formData, array_flip($editableKeys)));
                    $this->applyOrderProductSpecificationsFromFormRow($op, $formData);
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

            $this->record->refresh();
            if ($this->record->getStatus() === PurchaseOrderStatus::Initial) {
                $this->record->setStatus(PurchaseOrderStatus::Draft);
                $this->record->save();
            }

            if ($shouldRedirect) {
                $mainId = $this->record->order_id;
                if ($mainId !== null) {
                    $this->redirect(route('filament.app.resources.mains.view', ['record' => $mainId]) . '?tab=purchase', navigate: false);
                } else {
                    $this->redirect(route('filament.app.resources.stock-orders.index'));
                }
            }
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        }
    }

    public function placeStockOrder(?array $emailData = null): void
    {
        try {
            $this->save(false, false);

            // Send the stock order
            $this->record->submitStockOrder();

            $purchaseOrder = $this->record->purchaseOrders()->latest('id')->first();
            $pdfContent = $this->generateStockOrderPdf();
            if ($pdfContent !== null && $pdfContent !== '') {
                $safeRef = preg_replace('/[^a-zA-Z0-9\-_]/', '_', (string) ($this->record->getUidFormatted() ?? ''));
                $safeRef = is_string($safeRef) && $safeRef !== '' ? $safeRef : ('SO-' . $this->record->getId());
                $filename = 'inkooporder-' . $safeRef . '.pdf';

                $this->record->addMediaFromString($pdfContent)
                    ->usingFileName($filename)
                    ->toMediaCollection('documents');
            }

            if (is_array($emailData)) {
                if ($purchaseOrder instanceof PurchaseOrder) {
                    app(SendPurchaseOrderConfirmMailAction::class)->execute(
                        purchaseOrder: $purchaseOrder,
                        to: $emailData['to'] ?? [],
                        cc: $emailData['cc'] ?? [],
                        bcc: $emailData['bcc'] ?? [],
                        subject: $emailData['subject'] ?? '',
                        message: $emailData['message'] ?? '',
                        attachments: $emailData['attachments'] ?? []
                    );
                }
            }

            Notification::make()
                ->title('De inkooporder is verzonden. Deze toont binnen een minuut in het overzicht.')
                ->success()
                ->send();

            $mainId = $this->record->order_id;
            if ($mainId !== null) {
                $this->redirect(route('filament.app.resources.mains.view', ['record' => $mainId]) . '?tab=purchase', navigate: false);
            } else {
                $this->redirect(route('filament.app.resources.purchase-orders.ordered'));
            }
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        }
    }

    /**
     * Generate PDF from stock order blade. Returns PDF binary content or null on failure.
     */
    protected function generateStockOrderPdf(): ?string
    {
        $this->record->loadMissing(['supplier']);

        $html = view('order.stock_order', [
            'order' => $this->record,
            'products' => $this->record->getDocumentOrderProducts(),
        ])->render();

        $pdf = PDF::loadHTML($html)
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 0)
            ->setOption('margin-right', 0);

        $pdfOutput = $pdf->output();
        if ($pdfOutput === null || $pdfOutput === '') {
            return null;
        }

        return $pdfOutput;
    }

    public function placeStockOrderWithEmail(array $data): void
    {
        $attachments = ApproveStockOrderEmailAction::resolveAttachments($this->record, $data['attachments'] ?? []);

        $this->placeStockOrder([
            'to' => $data['to'] ?? [],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'] ?? '',
            'message' => $data['message'] ?? '',
            'attachments' => $attachments,
        ]);
    }

    protected function getFormActions(): array
    {
        return $this->formActionsUnlessRecordLockBlocked([
            Action::make('save')
                ->action(fn () => $this->save())
                ->extraAttributes([
                    'id' => 'save-button',
                ])
                ->label('Opslaan'),

            PreviewStockOrderAction::make('preview_stock_order'),

            ApproveStockOrderEmailAction::make('send_stock_order_email')->label('Verzenden'),

            $this->getCancelFormAction()
                ->action(fn () => $this->redirect(route('filament.app.resources.stock-orders.index')))
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


        // The base price + additional price (subtotal) should be set in the base price field so we can update it through the form.
        $orderProduct
            ->setCompanyPurchasePriceBase(round($orderProduct->getCompanyPurchasePriceSubtotal(), 2))
            ->setCompanyPurchasePriceAdditional(0)
            ->save();
        $orderProduct->refresh();

        // Set order product values in the state
        $state["record-{$orderProduct->getId()}"] = [
            'id' => $orderProduct->getId(),
            'qty' => $this->qtyForOrderProductRepeaterRow($orderProduct),
            'product_allows_fractional_qty' => $this->productAllowsFractionalQtyForOrderProductRow($orderProduct),
            'product_id' => $orderProduct->getProductId(),
            'value' => $orderProduct->getValue(),
            'unit' => $orderProduct->product->getUnit()?->getLabel() ?? '',
            'attribute_summary_basic' => $orderProduct->getAttributeSummaryBasic(),
            'company_purchase_price_base' => number_format($orderProduct->getCompanyPurchasePriceSubtotal(), 2, '.', ''),
            'company_purchase_price_total' => number_format($orderProduct->getCompanyPurchasePriceTotal(), 2, '.', ''),
            'company_sales_price_base' => number_format($orderProduct->getCompanySalesPriceSubtotal(), 2, '.', ''),
            'company_sales_price_total' => number_format($orderProduct->getCompanySalesPriceTotal(), 2, '.', ''),
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

        $this->dispatch('update-totals');
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

        if ($this->rejectNonPurchaseProductForPurchaseRepeater($product, $set)) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $salesPriceBase = round((float) ($product->getCompanySalesPrice() ?? 0), 2);

        $orderProductId = $get('id');
        if ($orderProductId && ($orderProduct = OrderProduct::find($orderProductId))) {
            // Load order product if already exists (when editing existing quote)
            /** @var OrderProduct $orderProduct */
            $qty = $this->normalizeQtyForProduct($product->getId(), $orderProduct->getQty() ?: 1);
            $orderProduct->update([
                'product_id' => $product->getId(),
                'value' => $product->getName(),
                'qty' => $qty,
                'company_purchase_price_base' => round($product->getCompanyPurchasePrice(), 2) ?: round($orderProduct->getCompanySalesPriceSubtotal(), 2),
                'company_purchase_price_additional' => 0,
                'company_sales_price_base' => $salesPriceBase,
                'company_sales_price_additional' => 0,
                'company_sales_price_subtotal' => $salesPriceBase,
                'company_sales_price_total' => $salesPriceBase * $qty,
                'attribute_summary_basic' => '',
                'attribute_summary_company' => '',
                'attribute_summary' => '',
                'vat' => 21.00,
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
                'company_sales_price_base' => $salesPriceBase,
                'company_sales_price_additional' => 0,
                'company_sales_price_subtotal' => $salesPriceBase,
                'company_sales_price_total' => $salesPriceBase,
                'vat' => 21.00,
                'supplier_id' => $product->supplier?->id,
                'order_id' => null,
            ]);
            $orderProduct->save();

            $set('id', $orderProduct->id);
        }
        $orderProduct->refresh();

        $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());

        $set('qty', $this->normalizeQtyForProduct($product->getId(), $get('qty') ?? 1));
        $set('value', $orderProduct->getValue());
        $set('unit', $product->getUnit()?->getLabel() ?? '');
        $set('attribute_summary_basic', '');
        $set('company_purchase_price_base', $orderProduct->getCompanyPurchasePriceSubtotal());
        $set('company_purchase_price_total', $orderProduct->getCompanyPurchasePriceTotal());
        $set('company_sales_price_base', $orderProduct->getCompanySalesPriceSubtotal());
        $set('company_sales_price_total', $orderProduct->getCompanySalesPriceTotal());

        // Remove initial empty product
        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState();
        $repeater->state(
            array_filter($state, fn($item) => $item['id'] !== 0)
        );

        $this->dispatch('update-totals');
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


    public function form(Schema $schema): Schema
    {
        $title = 'Nieuwe inkooporder';
        if ($this->record->getReference()) {
            $title = 'Concept inkooporder';
        }
        if ($this->record->getUid()) {
            $title = 'Inkooporder: #' . $this->record->getUidFormatted();
        }

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Inkooporder-overzicht',
                        'url' => route('filament.app.resources.stock-orders.index'),
                    ]),

                Section::make($title)
                    ->columns(2)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Group::make()
                            ->columnSpan(1)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                Select::make('supplier_id')
                                    ->label('Ter attentie van')
                                    ->inlineLabel()
                                    ->relationship('supplier', 'name')
                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap ter-attentie-van-field'])
                                    ->searchable()
                                    ->preload()
                                    ->disabled()
                                    ->columnSpanFull()
                                    ->selectablePlaceholder(false),
                            ]),

                        Grid::make(2)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'borderTop'])

                            ->schema([
                                Section::make('Factuuradres')
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->schema([
                                        View::make('filament.resources.purchase-orders.partials.invoice-address')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Leveradres')
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->heading('')
                                    ->schema([
                                        Select::make('shipping_address_type')
                                            ->label('Leveradres')
                                            ->inlineLabel()
                                            ->selectablePlaceholder(false)
                                            ->options(fn (Get $get) => $this->getDeliveryAddressTypeOptions($get))
                                            ->default(fn () => $this->getInitialDeliveryAddressTypeKey())
                                            ->extraAttributes(['class' => 'diffSelect address-type-select'])
                                            ->columnSpanFull()
                                            ->live()
                                            ->afterStateUpdated(fn (Set $set, $state) => $this->syncDeliveryAddressFromType($set, $state)),

                                        View::make('filament.resources.stock-orders.partials.delivery-address-preview')
                                            ->visible(fn (Get $get) => ($get('shipping_address_type') ?? '') !== 'custom')
                                            ->columnSpanFull(),

                                        Group::make()
                                            ->visible(fn (Get $get) => ($get('shipping_address_type') ?? '') === 'custom')
                                            ->extraAttributes(fn (Get $get) => [
                                                'class' => ($get('shipping_address_type') ?? '') === 'custom' ? 'address-contact-fields' : 'hideForm',
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
                                TableColumn::make(new HtmlString('<span>Totaal: Inkoop</span> <span class="taxOverview">(excl. BTW)</span>')),
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

                                ProductSelect::make('product_id')
                                    ->required()
                                    ->supplierId(fn (): ?int => $this->record?->getSupplierId())
                                    ->excludeServiceProducts(fn (): bool => $this->record?->getSupplierId() !== null)
                                    ->salesItemsOnly(false)
                                    ->purchaseItemsOnly()
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
                                    ->numeric()
                                    ->maxValue(100_000)
                                    ->readOnly()
                                    ->formatStateUsing(fn($state) => number_format((float)$state, 2, '.', ''))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                TextInput::make('company_purchase_price_total')
                                    ->label(new HtmlString('<span>Totaal: Inkoop</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->numeric()
                                    ->maxValue(100_000)
                                    ->formatStateUsing(fn($state) => number_format((float)$state, 2, '.', ''))
                                    ->disabled()
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),
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
                                    ->viewData([
                                        'showCompanySalesPrice' => false,
                                    ])
                                    ->statePath('totals_summary')
                                    ->columnSpanFull(),
                            ])
            ])
            ]);
    }

    protected function updatePricesJs(): string
    {
        return <<<'JS'
            (() => {
                const parse = (value) => typeof value === 'string'
                    ? (parseFloat(value.replace(',', '.')) || 0)
                    : (value ?? 0);
                const round = (value) => parseFloat(value.toPrecision(12), 10).toFixed(2);
                const companyPurchasePriceBase = parse($get('company_purchase_price_base'));

                const qty = $get('qty') ?? 1;
                const companyPurchasePriceTotal = companyPurchasePriceBase * Math.max(1, qty);

                $set('company_purchase_price_base', round(companyPurchasePriceBase));
                $set('company_purchase_price_total', round(companyPurchasePriceTotal));

                $dispatch('update-totals');
            })()
            JS;
    }

    private function getDeliveryAddressTypeOptions(Get $get): array
    {
        return [
            'rd' => 'RD Mobility',
            'custom' => 'Zelf ingeven',
        ];
    }

    public function getStockOrderLeveradresPreviewHtml(string $typeKey): string
    {
        if ($typeKey === 'custom') {
            return '';
        }

        if ($typeKey === 'rd') {
            $rd = Customer::getRdMobilityCustomer();
            $rd->loadMissing(['billingAddress.customer', 'billingAddress.country']);
            $address = $rd->billingAddress;

            if ($address === null) {
                return '<p class="text-gray-500 dark:text-gray-400 text-sm">Geen adres beschikbaar voor deze keuze.</p>';
            }

            return $address->getAddressTemplateIncNameFormatted();
        }

        return '<p class="text-gray-500 dark:text-gray-400 text-sm">Geen adres beschikbaar voor deze keuze.</p>';
    }

    private function getInitialDeliveryAddressTypeKey(): string
    {
        $additional = $this->record->getAdditional() ?? [];
        if (! empty($additional['shipping_address_type_key'])) {
            return (string) $additional['shipping_address_type_key'];
        }

        return 'rd';
    }

    private function emptyAddressFormState(): array
    {
        return [
            'postcode' => null,
            'street' => null,
            'house_number' => null,
            'house_number_addition' => null,
            'city' => null,
            'country_id' => Country::NL_ID,
        ];
    }

    /**
     * @return array{address: array|null, name: string|null}
     */
    private function getDeliveryAddressForTypeKey(string $key): array
    {
        if ($key === 'custom') {
            return ['address' => $this->emptyAddressFormState(), 'name' => null];
        }

        $source = null;
        $name = null;
        if ($key === 'rd') {
            $rdCustomer = Customer::getRdMobilityCustomer();
            if ($rdCustomer->billingAddress !== null) {
                $source = $rdCustomer->billingAddress;
                $name = $rdCustomer->getName();
            }
        } elseif ($key === 'customer') {
            $main = $this->record->main;
            $customer = $main?->customer;
            if ($customer?->address !== null) {
                $source = $customer->address;
                $name = $customer->getName();
            }
        } elseif ($key === 'dealer') {
            $main = $this->record->main;
            $company = $main?->company;
            if ($company?->address !== null) {
                $source = $company->address;
                $name = $company->getName();
            }
        }

        $address = $source !== null ? [
            'street' => $source->street,
            'house_number' => $source->house_number,
            'house_number_addition' => $source->house_number_addition,
            'postcode' => $source->postcode,
            'city' => $source->city,
            'country_id' => $source->country_id ?? Country::NL_ID,
        ] : null;

        return ['address' => $address, 'name' => $name];
    }

    private function syncDeliveryAddressFromType(Set $set, mixed $state): void
    {
        $key = is_string($state) ? $state : ($state?->value ?? null);
        if ($key === null || $key === '') {
            return;
        }

        $result = $this->getDeliveryAddressForTypeKey((string) $key);
        $set('additional.shipping_name', $result['name']);
        if ($result['address'] !== null) {
            $set('additional.delivery_address', $result['address']);
        }
    }

    protected function hydrateDeliveryAddressFormFromRecord(): void
    {
        $data = $this->data ?? [];

        $additional = $this->record->getAdditional() ?? [];
        $typeKey = $additional['shipping_address_type_key'] ?? $this->getInitialDeliveryAddressTypeKey();
        $data['shipping_address_type'] = $typeKey;
        $data['additional'] = array_merge($data['additional'] ?? [], [
            'shipping_name' => $additional['shipping_name'] ?? null,
            'delivery_address' => $additional['delivery_address'] ?? null,
        ]);

        $deliveryAddr = $data['additional']['delivery_address'] ?? null;
        $hasAddress = $deliveryAddr && (($deliveryAddr['street'] ?? '') !== '' || ($deliveryAddr['city'] ?? '') !== '');
        if (! $hasAddress) {
            $result = $this->getDeliveryAddressForTypeKey($typeKey);
            $data['additional']['shipping_name'] = $result['name'];
            $data['additional']['delivery_address'] = $result['address'];
        }

        $this->form->fill($data);
    }

    public function updatedDocumentFiles(): void
    {
        if ($this->documentFiles === []) {
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

        $newMediaIds = [];
        $count = 0;

        foreach ($this->documentFiles as $file) {
            if (! $file) {
                continue;
            }
            $media = $this->record->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('documents');
            $newMediaIds[] = 'so_doc_' . $media->id;
            $count++;
        }

        $this->documentFiles = [];
        $this->record->unsetRelation('media');
        $this->record->refresh();
        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);
        $this->dispatch('$refresh');

        if ($count > 0) {
            Notification::make()
                ->title($count === 1 ? 'Document geüpload.' : "{$count} documenten geüpload.")
                ->success()
                ->send();
        }
    }

    /**
     * @param  array<int, string>  $newMediaIds
     */
    protected function mergeNewUploadedAttachmentsIntoMountedAction(array $newMediaIds): void
    {
        if ($newMediaIds === [] || empty($this->mountedActions)) {
            return;
        }

        $index = null;
        foreach ($this->mountedActions as $key => $mounted) {
            if (! is_array($mounted)) {
                continue;
            }
            if (($mounted['name'] ?? null) === 'send_stock_order_email') {
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

        if (! array_key_exists('data', $this->mountedActions[$index])) {
            $this->mountedActions[$index]['data'] = [];
        }

        $current = $this->mountedActions[$index]['data']['attachments'] ?? [];
        $current = is_array($current) ? $current : [];
        $merged = array_values(array_unique(array_merge($current, $newMediaIds)));
        $this->mountedActions[$index]['data']['attachments'] = $merged;
    }
}

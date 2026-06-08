<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Actions\SendPurchaseOrderConfirmMailAction;
use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Concerns\ManagesRecordLock;
use App\Filament\Support\RecordLockEditPage;
use App\Filament\Forms\AddressFormSchema;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Actions\ApprovePurchaseOrderEmailAction;
use App\Filament\Resources\PurchaseOrderResource\Actions\PreviewPurchaseOrderAction;
use App\Models\Customer;
use App\Models\Country;
use App\Models\Order\Main;
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
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\OrderProductSelectionLockService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * @property PurchaseOrder $record
 */
class EditPurchaseOrder extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;
    use ManagesRecordLock;

    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = RecordLockEditPage::VIEW;

    protected $listeners = [
        'addOrderProduct' => 'addOrderProduct',
        'loadBomProducts' => 'loadBomProducts',
    ];

    /**
     * @var Collection<int, array> $orderProducts
     */
    public ?Collection $orderProducts = null;

    public ?string $companyPurchasePriceDiscount = null;

    /** When set, save() redirects back to this order (main) view with purchase tab. */
    public ?int $returnToOrderId = null;

    /** @var array<int, \Illuminate\Http\UploadedFile> */
    public array $documentFiles = [];

    public function mount(int|string $record): void
    {
        if (! $this->mountRecordLockGate($record)) {
            return;
        }

        parent::mount($record);

        $this->completeRecordLockMount();

        $returnToOrder = request()->query('return_to_order');
        $this->returnToOrderId = $returnToOrder !== null && $returnToOrder !== '' ? (int) $returnToOrder : null;

        $this->orderProducts ??= collect();
        $this->fillForm();
        $this->loadOrderProducts();
        $this->hydrateDeliveryAddressFormFromRecord();

        $user = Auth::user();
        if ($user instanceof User && $this->record->getStatus() === PurchaseOrderStatus::Initial) {
            app(OrderProductSelectionLockService::class)->lockLinesForConceptPurchaseOrder($this->record, $user);
        }
    }

    public function getBackToUrl(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        if ($orderId !== null) {
            return route('filament.app.resources.mains.view', ['record' => $orderId]) . '?tab=purchase';
        }
        return route('filament.app.resources.purchase-orders.index');
    }

    public function getBackToTitle(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        return $orderId !== null ? 'Aanvraag' : 'Inkooporder-overzicht';
    }

    protected function getRecordLockBackUrl(): string
    {
        return $this->getBackToUrl();
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            Notification::make()
                ->title('Formulier ongeldig')
                ->body('Vul eerst alle verplichte velden in (bijv. Referentie) voordat je verzendt.')
                ->warning()
                ->send();
            throw $e;
        }
    }

    protected function resolveRecord(int|string $key): PurchaseOrder
    {
        return PurchaseOrder::findOrFail($key);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $typeKey = $data['shipping_address_type'] ?? null;
        $typeKeyStr = is_string($typeKey) ? $typeKey : (string) ($typeKey ?? '');
        $typeKeyStr = $this->normalizeShippingAddressTypeKeyForStorage($typeKeyStr);

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

    public function applyFormAndSaveForPreview(): void
    {
        $data = $this->form->getState();
        $this->handleRecordUpdate($this->getRecord(), $data);
        $this->save(false, false);
        $this->record->refresh();
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save();

            $this->record->setReferenceNumber($this->data['reference_number'] ?? $this->record->getReferenceNumber());
            $this->record->save();

            $editableKeys = ['qty', 'status'];
            foreach ($this->orderProducts as $orderProduct) {
                $formData = array_find($this->data['order_products'] ?? [], fn ($op) => ($op['id'] ?? null) == $orderProduct['id']) ?? [];
                $op = OrderProduct::find($orderProduct['id'] ?? null);
                if ($op !== null && ! empty($formData)) {
                    $safeData = array_intersect_key($formData, array_flip($editableKeys));
                    if ($this->record->getStatus() === PurchaseOrderStatus::Initial) {
                        unset($safeData['status']);
                    }
                    $op->update($safeData);
                    $this->applyOrderProductSpecificationsFromFormRow($op, $formData);
                }
                if ($op !== null) {
                    $op->setPurchaseOrderId($this->record->getId());
                    $op->save();
                }
            }

            $this->syncOrderProductSortFromFormState();

            if ($shouldRedirect) {
                if ($this->returnToOrderId !== null) {
                    $this->redirect(
                        route('filament.app.resources.mains.view', ['record' => $this->returnToOrderId]) . '?tab=purchase',
                        navigate: false
                    );
                    return;
                }
                $this->redirect(route('filament.app.resources.purchase-orders.index'));
            }
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        }
    }

    public function placePurchaseOrderWithEmail(array $data): void
    {
        $mainId = $this->record->main_id;
        $main = $mainId !== null ? Main::find($mainId) : null;
        $referenceNumber = $this->generateMtoReferenceNumber($main);
        $this->record->setReferenceNumber($referenceNumber);

        $attachments = ApprovePurchaseOrderEmailAction::resolveAttachments(
            $this->record,
            $data['attachments'] ?? []
        );

        $to = $data['to'] ?? [];
        $cc = $data['cc'] ?? [];
        $bcc = $data['bcc'] ?? [];

        app(SendPurchaseOrderConfirmMailAction::class)->execute(
            purchaseOrder: $this->record,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            subject: $data['subject'] ?? '',
            message: $data['message'] ?? '',
            attachments: $attachments
        );

        $this->placePurchaseOrder();
    }

    public function placePurchaseOrder(): void
    {
        try {
            $data = $this->form->getState();
            $this->handleRecordUpdate($this->getRecord(), $data);
            $this->save(false, false);

            $mainId = $this->record->main_id;
            $main = $mainId !== null ? Main::find($mainId) : null;

            $referenceNumber = $this->generateMtoReferenceNumber($main);
            $this->record->setReferenceNumber($referenceNumber);
            $this->record->setStatus(PurchaseOrderStatus::Purchased);
            $this->record->sent_at = now();
            $this->record->save();

            if ($main !== null) {
                $userName = $this->record->author?->getName() ?? '[systeem]';
                $main->orderEvents()->create([
                    'type' => 'Inkooporder ' . $this->record->getReferenceNumber() . ' aangemaakt door ' . $userName,
                    'data' => [],
                    'user_id' => $this->record->getAuthorId(),
                ]);
            }

            $pdfContent = $this->generatePurchaseOrderPdf();
            if ($pdfContent !== null && $pdfContent !== '') {
                $safeRef = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $this->record->getReferenceNumber());
                $filename = 'inkooporder-' . $safeRef . '.pdf';
                $this->record->addMediaFromString($pdfContent)
                    ->usingFileName($filename)
                    ->toMediaCollection('documents');
            }

            OrderProduct::where('purchase_order_id', $this->record->getId())->update([
                'status' => OrderProductStatus::Purchased->value,
                'fulfillment_type' => FulfillmentType::MakeToOrder->value,
                'purchased_at' => now(),
            ]);

            if ($main !== null && $main->getOrderStatus() === OrderStatus::OrderAwaitingPurchase) {
                $main->changeOrderStatus(OrderStatus::PartiallyPurchased);
            }

            Notification::make()
                ->title('De inkooporder is verzonden.')
                ->success()
                ->send();

            if ($this->returnToOrderId !== null) {
                $this->redirect(
                    route('filament.app.resources.mains.view', ['record' => $this->returnToOrderId]) . '?tab=purchase',
                    navigate: false
                );
                return;
            }
            $this->redirect(route('filament.app.resources.purchase-orders.ordered'));
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        }
    }

    /**
     * Generate MTO reference number from main uid (e.g. A-2026-0007 -> MTO-2026-0007, second PO -> MTO-2026-0007-2).
     */
    protected function generateMtoReferenceNumber(?Main $main): string
    {
        $uid = $main?->getUid() ?? '';
        $parts = explode('-', $uid, 2);
        $base = 'MTO-' . ($parts[1] ?? $uid);
        if ($base === 'MTO-' || $base === 'MTO') {
            $base = 'MTO-' . now()->format('Y-m-d-His');
        }

        $mainId = $this->record->main_id;
        $existingCount = $mainId !== null
            ? PurchaseOrder::where('main_id', $mainId)
                ->where('id', '!=', $this->record->getId())
                ->where(function ($q) use ($base): void {
                    $q->where('reference_number', $base)
                        ->orWhere('reference_number', 'like', $base . '-%');
                })
                ->count()
            : 0;

        /* Eerste inkooporder: geen suffix. Tweede: -1, derde: -2, etc. */
        return $existingCount === 0 ? $base : $base . '-' . $existingCount;
    }

    /**
     * Generate PDF from purchase order blade. Returns PDF binary content or null on failure.
     */
    protected function generatePurchaseOrderPdf(): ?string
    {
        $this->record->loadMissing(['orderProducts', 'supplier']);
        $order = $this->record;
        $products = $this->record->orderProducts;

        $html = view('order.purchase_order', [
            'order' => $order,
            'products' => $products,
        ])->render();

        $this->record->documents()->create([
            'content' => $html,
        ]);

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

        $main = $this->record->main;
        if ($main === null) {
            $this->documentFiles = [];

            return;
        }

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
            $media = $main->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('product_documents');
            $newMediaIds[] = 'media_' . $media->id;
            $count++;
        }

        $this->documentFiles = [];
        $main->unsetRelation('media');
        $this->record->unsetRelation('main');

        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);

        $this->dispatch('$refresh');

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
            if (($mounted['name'] ?? null) === 'send_purchase_order_email') {
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

    protected function getFormActions(): array
    {
        return $this->formActionsUnlessRecordLockBlocked([
            PreviewPurchaseOrderAction::make('preview_purchase_order'),
            ApprovePurchaseOrderEmailAction::make('send_purchase_order_email')->label('Verzenden'),

            $this->getCancelFormAction()
                ->action(function (): void {
                    if ($this->returnToOrderId !== null) {
                        $this->redirect(
                            route('filament.app.resources.mains.view', ['record' => $this->returnToOrderId]) . '?tab=purchase',
                            navigate: false
                        );
                        return;
                    }
                    $this->redirect(route('filament.app.resources.purchase-orders.index'));
                })
                ->extraAttributes(['class' => 'white']),
        ]);
    }

    public function addOrderProduct(array $data): void
    {
        $orderProductId = $data['orderProductId'] ?? null;
        if (! $orderProductId) {
            return;
        }

        $orderProduct = OrderProduct::find($orderProductId);
        if (! $orderProduct) {
            return;
        }

        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState() ?? [];

        $orderProduct
            ->setCompanyPurchasePriceBase(round($orderProduct->getCompanyPurchasePriceSubtotal(), 2))
            ->setCompanyPurchasePriceAdditional(0)
            ->save();
        $orderProduct->refresh();

        $state["record-{$orderProduct->getId()}"] = [
            'id' => $orderProduct->getId(),
            'qty' => $this->qtyForOrderProductRepeaterRow($orderProduct),
            'product_allows_fractional_qty' => $this->productAllowsFractionalQtyForOrderProductRow($orderProduct),
            'product_id' => $orderProduct->getProductId(),
            'value' => $orderProduct->getValue(),
            'unit' => $orderProduct->product?->getUnit()?->getLabel() ?? '',
            'attribute_summary_basic' => $orderProduct->getAttributeSummaryBasic(),
            'company_purchase_price_base' => number_format($orderProduct->getCompanyPurchasePriceSubtotal(), 2, '.', ''),
            'company_purchase_price_total' => number_format($orderProduct->getCompanyPurchasePriceTotal(), 2, '.', ''),
            'company_sales_price_base' => number_format($orderProduct->getCompanySalesPriceSubtotal(), 2, '.', ''),
            'company_sales_price_total' => number_format($orderProduct->getCompanySalesPriceTotal(), 2, '.', ''),
            'supplier_id' => $orderProduct->getSupplierId(),
        ];

        $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());

        foreach ($state as $key => $item) {
            $isEmpty = ($item['id'] ?? 0) === 0 && empty($item['product_id'] ?? null);
            if ($isEmpty) {
                unset($state[$key]);
            }
        }

        $repeater->state($state);
        $this->dispatch('update-totals');
    }

    public function loadBomProducts(?array $orderProducts): void
    {
        if ($orderProducts === null) {
            return;
        }
        foreach ($orderProducts as $orderProductId) {
            $this->addOrderProduct(['orderProductId' => $orderProductId]);
        }
        $this->dispatch('update-totals');
    }

    public function loadOrderProduct(Get $get, Set $set): void
    {
        $product = Product::find($get('product_id'));
        if (! $product) {
            return;
        }

        if ($this->rejectNonPurchaseProductForPurchaseRepeater($product, $set)) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $salesPriceBase = round((float) ($product->getCompanySalesPrice() ?? 0), 2);
        $orderProductId = $get('id');
        if ($orderProductId && ($orderProduct = OrderProduct::find($orderProductId))) {
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

        $repeater = $this->form->getComponent('order_products');
        $state = $repeater->getState() ?? [];
        $repeater->state(array_filter($state, fn ($item) => ($item['id'] ?? 0) !== 0));
        $this->dispatch('update-totals');
    }

    public function loadOrderProducts(): void
    {
        $products = $this->record->orderProducts()
            ->get();

        foreach ($products as $orderProduct) {
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

    public function form(Schema $schema): Schema
    {
        $title = 'Nieuwe inkooporder';

        if ($this->record->getStatus() != PurchaseOrderStatus::Initial) {
            $title = 'Inkooporder: #' . $this->record->getReferenceNumber();
        }

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => $this->getBackToTitle(),
                        'url' => $this->getBackToUrl(),
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

                        Grid::make(3)
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
                                            ->options(fn (): array => $this->getDeliveryAddressTypeOptions())
                                            ->default(fn () => $this->getInitialDeliveryAddressTypeKey())
                                            ->extraAttributes(['class' => 'diffSelect address-type-select'])
                                            ->columnSpanFull()
                                            ->live()
                                            ->afterStateUpdated(fn (Set $set, $state) => $this->syncDeliveryAddressFromType($set, $state)),

                                        View::make('filament.resources.purchase-orders.partials.delivery-address-preview')
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

//                        Group::make()
//                            ->columnSpanFull()
//                            ->extraAttributes(['class' => 'borderTop detailsSection'])
//                            ->schema([
//                                TextInput::make('reference_number')
//                                    ->label('Referentie')
//                                    ->inlineLabel()
//                                    ->required()
//                                    ->columnSpanFull(),
//                            ]),

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
                                Hidden::make('company_sales_price_base'),
                                Hidden::make('company_sales_price_total'),

                                $this->orderProductAllowsFractionalQtyHiddenField(),

                                $this->configureOrderProductQtyField(TextInput::make('qty'), disabled: true),

                                TextInput::make('unit')
                                    ->label('Eenheid')
                                    ->disabled()
                                    ->live()
                                    ->extraFieldWrapperAttributes(['class' => 'input-unit']),

                                ProductSelect::make('product_id')
                                    ->required()
                                    ->supplierId(fn (): ?int => $this->record?->getSupplierId())
                                    ->salesItemsOnly(false)
                                    ->purchaseItemsOnly()
                                    ->extraAttributes(['class' => 'input-value'])
                                    ->extraFieldWrapperAttributes(['class' => 'product-select'])
                                    ->disabled()
                                    ->afterStateUpdated(function (Get $get, Set $set) {
                                        $this->loadOrderProduct($get, $set);
                                    }),

                                Textarea::make('attribute_summary_basic')
                                    ->label('Specificaties')
                                    ->rows(3)
                                    ->formatStateUsing(fn ($state) => arrayToTextareaString($state ?? []))
                                    ->extraFieldWrapperAttributes(['class' => 'input-specifications'])
                                    ->columnSpanFull(),

                                TextInput::make('company_purchase_price_base')
                                    ->label(new HtmlString('<span>Inkoop</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->numeric()
                                    ->maxValue(100_000)
                                    ->readOnly()
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, '.', ''))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                TextInput::make('company_purchase_price_total')
                                    ->label(new HtmlString('<span>Totaal: Inkoop</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->numeric()
                                    ->maxValue(100_000)
                                    ->readOnly()
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, '.', ''))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->columnSpanFull(),

                        Section::make('Samenvatting')
                            ->columnSpanFull()
                            ->schema([
                                View::make('filament.resources.quote-resource.totals')
                                    ->viewData(['showCompanySalesPrice' => false])
                                    ->statePath('totals_summary')
                                    ->columnSpanFull(),
                            ]),
                    ]),
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

    /**
     * Delivery address type options for purchase orders (aligned with main order address parties).
     */
    private function getDeliveryAddressTypeOptions(): array
    {
        $options = ['rd' => 'RD Mobility'];

        $main = $this->record->main?->loadMissing(['customer', 'billingCustomer', 'shippingCustomer']);
        if ($main?->customer_id) {
            $options['customer'] = 'Klant';
        }
        if ($main?->billing_customer_id) {
            $options['billing'] = 'Factuurgegevens';
        }
        if ($main?->shipping_customer_id) {
            $options['shipping'] = 'Levergegevens';
        }

        $options['custom'] = 'Zelf ingeven';

        return $options;
    }

    private function getInitialDeliveryAddressTypeKey(): string
    {
        $additional = $this->record->getAdditional() ?? [];
        if (! empty($additional['shipping_address_type_key'])) {
            return $this->normalizeShippingAddressTypeKeyForStorage((string) $additional['shipping_address_type_key']);
        }

        $main = $this->record->main;
        if ($main instanceof Main && $main->usesUnitSimplifiedSalesFlow()) {
            return $main->billing_customer_id !== null ? 'billing' : 'rd';
        }

        return 'rd';
    }

    /**
     * Maps legacy stored keys and drops invalid keys when relations are missing.
     */
    private function normalizeShippingAddressTypeKeyForStorage(string $key): string
    {
        if ($key === 'custom') {
            return 'custom';
        }

        if ($key === '') {
            return 'rd';
        }

        if ($key === 'dealer') {
            $key = 'billing';
        }

        $main = $this->record->main;

        return match (true) {
            $key === 'billing' && $main?->billing_customer_id === null => 'rd',
            $key === 'shipping' && $main?->shipping_customer_id === null => 'rd',
            $key === 'customer' && $main?->customer_id === null => 'rd',
            default => $key,
        };
    }

    public function getPurchaseOrderLeveradresPreviewHtml(string $typeKey): string
    {
        if ($typeKey === 'custom') {
            return '';
        }

        $key = $this->normalizeShippingAddressTypeKeyForStorage($typeKey);

        $main = $this->record->main?->loadMissing([
            'customer.shippingAddress',
            'customer.billingAddress',
            'customer.address',
            'billingCustomer.shippingAddress',
            'billingCustomer.billingAddress',
            'billingCustomer.address',
            'shippingCustomer.shippingAddress',
            'shippingCustomer.billingAddress',
            'shippingCustomer.address',
        ]);

        $address = null;
        if ($key === 'rd') {
            $rd = Customer::getRdMobilityCustomer();
            $rd->loadMissing(['billingAddress.customer', 'billingAddress.country']);
            $address = $rd->billingAddress;
        } elseif ($key === 'customer') {
            $address = $main?->customer?->getPhysicalDeliveryAddress();
            $address?->loadMissing(['customer', 'country']);
        } elseif ($key === 'billing') {
            $address = $main?->billingCustomer?->getPhysicalDeliveryAddress();
            $address?->loadMissing(['customer', 'country']);
        } elseif ($key === 'shipping') {
            $address = $main?->shippingCustomer?->getPhysicalDeliveryAddress();
            $address?->loadMissing(['customer', 'country']);
        }

        if ($address === null) {
            return '<p class="text-gray-500 dark:text-gray-400 text-sm">Geen adres beschikbaar voor deze keuze.</p>';
        }

        return $address->getAddressTemplateIncNameFormatted();
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
            $main = $this->record->main?->loadMissing(['customer']);
            $customer = $main?->customer;
            $source = $customer?->getPhysicalDeliveryAddress();
            $name = $customer?->getName();
        } elseif ($key === 'billing' || $key === 'dealer') {
            $main = $this->record->main?->loadMissing(['billingCustomer']);
            $customer = $main?->billingCustomer;
            $source = $customer?->getPhysicalDeliveryAddress();
            $name = $customer?->getName();
        } elseif ($key === 'shipping') {
            $main = $this->record->main?->loadMissing(['shippingCustomer']);
            $customer = $main?->shippingCustomer;
            $source = $customer?->getPhysicalDeliveryAddress();
            $name = $customer?->getName();
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
        $raw = is_string($state) ? $state : (string) ($state?->value ?? '');
        if ($raw === '') {
            return;
        }

        $normalized = $this->normalizeShippingAddressTypeKeyForStorage($raw);
        if ($normalized !== $raw) {
            $set('shipping_address_type', $normalized);
        }

        $result = $this->getDeliveryAddressForTypeKey($normalized);
        $set('additional.shipping_name', $result['name']);
        if ($result['address'] !== null) {
            $set('additional.delivery_address', $result['address']);
        }
    }

    protected function hydrateDeliveryAddressFormFromRecord(): void
    {
        $data = $this->form->getState();

        $repeater = $this->form->getComponent('order_products');
        $data['order_products'] = $repeater->getState() ?? [];

        $additional = $this->record->getAdditional() ?? [];
        $rawKey = $additional['shipping_address_type_key'] ?? $this->getInitialDeliveryAddressTypeKey();
        $typeKey = $this->normalizeShippingAddressTypeKeyForStorage(is_string($rawKey) ? $rawKey : (string) $rawKey);
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
}

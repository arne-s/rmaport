<?php

namespace App\Filament\Resources\ReleaseOrders\Pages;

use App\Actions\SendReleaseOrderConfirmMailAction;
use App\Enums\CustomerType;
use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Forms\AddressFormSchema;
use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Resources\ReleaseOrders\Actions\ApproveReleaseOrderEmailAction;
use App\Filament\Resources\ReleaseOrders\Actions\PreviewReleaseOrderAction;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use App\Models\Customer;
use App\Models\Country;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\ReleaseOrder;
use App\Models\User;
use App\Services\OrderProductSelectionLockService;
use Barryvdh\Snappy\Facades\SnappyPdf as PDF;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * @property ReleaseOrder $record
 */
class EditReleaseOrder extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;

    /** @var class-string<\App\Filament\Resources\ReleaseOrders\ReleaseOrderResource> */
    protected static string $resource = \App\Filament\Resources\ReleaseOrders\ReleaseOrderResource::class;

    /**
     * @var Collection<int, array>|null
     */
    public ?Collection $orderProducts = null;

    public ?int $returnToOrderId = null;

    public bool $releaseOrderIsOrphaned = false;

    /** @var array<int, \Illuminate\Http\UploadedFile> */
    public array $documentFiles = [];

    /**
     * Used by the shared totals view (quote-resource.totals). Not persisted on release orders.
     *
     * @var string|null
     */
    public ?string $companyPurchasePriceDiscount = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $returnToOrder = request()->query('return_to_order');
        $this->returnToOrderId = $returnToOrder !== null && $returnToOrder !== '' ? (int)$returnToOrder : null;
        $this->orderProducts ??= collect();
        $this->fillForm();
        $this->detectOrphanedReleaseOrder();
        $this->loadOrderProducts();
        $this->hydrateDeliveryAddressFormFromRecord();

        $user = Auth::user();
        if ($user instanceof User && $this->record->getStatus() === ReleaseOrderStatus::Initial) {
            app(OrderProductSelectionLockService::class)->lockLinesForConceptReleaseOrder($this->record, $user);
        }
    }

    protected function detectOrphanedReleaseOrder(): void
    {
        $this->releaseOrderIsOrphaned = ! $this->record->hasLinkedOrderProducts();
    }

    public function getBackToUrl(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        if ($orderId !== null) {
            return route('filament.app.resources.mains.view', ['record' => $orderId]) . '?tab=purchase';
        }
        return ReleaseOrderResource::getUrl('index');
    }

    public function getBackToTitle(): string
    {
        $orderId = $this->returnToOrderId ?? $this->record?->main_id;
        return $orderId !== null ? 'Aanvraag' : 'Afroep-overzicht';
    }

    public function mountAction(string $name, array $arguments = [], array $context = []): mixed
    {
        try {
            return parent::mountAction($name, $arguments, $context);
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            Notification::make()
                ->title('Formulier ongeldig')
                ->body('Vul eerst alle verplichte velden in voordat je preview of verzenden gebruikt.')
                ->warning()
                ->send();
            throw $e;
        }
    }

    protected function resolveRecord(int|string $key): ReleaseOrder
    {
        return ReleaseOrder::findOrFail($key);
    }

    public function getTitle(): string
    {
        if ($this->record->getStatus() === ReleaseOrderStatus::Initial || $this->record->getReferenceNumber() === 'concept') {
            return 'Nieuw afroepverzoek';
        }
        return 'Afroepverzoek: #' . $this->record->getReferenceNumber();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $typeKey = $data['shipping_address_type'] ?? null;
        $typeKeyStr = is_string($typeKey) ? $typeKey : (string) ($typeKey ?? '');
        $contactperson = trim((string) ($data['contactperson'] ?? ''));

        if ($typeKeyStr === 'custom') {
            $additional = array_merge($this->record->getAdditional() ?? [], [
                'shipping_address_type_key' => 'custom',
                'delivery_address' => $data['additional']['delivery_address'] ?? null,
                'shipping_name' => $data['additional']['shipping_name'] ?? null,
                'contactperson' => $contactperson,
            ]);
        } else {
            $key = $typeKeyStr !== '' ? $typeKeyStr : 'rd';
            $resolved = $this->getDeliveryAddressForTypeKey($key);
            $additional = array_merge($this->record->getAdditional() ?? [], [
                'shipping_address_type_key' => $key,
                'delivery_address' => $resolved['address'],
                'shipping_name' => $resolved['name'],
                'contactperson' => $contactperson,
            ]);
        }

        $data['additional'] = $additional;
        unset($data['shipping_address_type']);

        return $data;
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if ($this->releaseOrderIsOrphaned) {
            return;
        }

        try {
            parent::save();
            $this->record->setReferenceNumber($this->data['reference_number'] ?? $this->record->getReferenceNumber());
            $this->record->save();

            $editableKeys = ['qty', 'status'];
            foreach ($this->orderProducts as $orderProduct) {
                $formData = array_find($this->data['order_products'] ?? [], fn($op) => ($op['id'] ?? null) == $orderProduct['id']) ?? [];
                $op = OrderProduct::find($orderProduct['id'] ?? null);
                if ($op !== null && !empty($formData)) {
                    $safeData = array_intersect_key($formData, array_flip($editableKeys));
                    if ($this->record->getStatus() === ReleaseOrderStatus::Initial) {
                        unset($safeData['status']);
                    }
                    $op->update($safeData);
                    $this->applyOrderProductSpecificationsFromFormRow($op, $formData);
                }
                if ($op !== null) {
                    $op->setReleaseOrderId($this->record->getId());
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
                $this->redirect(ReleaseOrderResource::getUrl('index'));
            }
        } catch (ValidationException $e) {
            $this->dispatch('scrollToFirstError');
            throw $e;
        }
    }

    public function applyFormAndSaveForPreview(): void
    {
        $this->save(false, false);
        $this->record->refresh();
    }

    public function placeReleaseOrder(): void
    {
        if ($this->releaseOrderIsOrphaned) {
            return;
        }

        $this->validate();
        $data = $this->form->getState();
        $this->handleRecordUpdate($this->getRecord(), $data);
        $this->save(false, false);

        $mainId = $this->record->main_id;
        $main = $mainId !== null ? Main::find($mainId) : null;
        $referenceNumber = $this->generateReferenceNumber($main);
        $this->record->setReferenceNumber($referenceNumber);
        $this->record->setStatus(ReleaseOrderStatus::Purchased);
        $this->record->sent_at = now();
        $this->record->save();

        if ($main !== null) {
            $userName = $this->record->author?->getName() ?? '[systeem]';
            $main->orderEvents()->create([
                'type' => 'Afroeporder ' . $this->record->getReferenceNumber() . ' aangemaakt door ' . $userName,
                'data' => [],
                'user_id' => $this->record->getAuthorId(),
            ]);
        }

        OrderProduct::where('release_order_id', $this->record->getId())->update([
            'status' => OrderProductStatus::Sent->value,
            'fulfillment_type' => FulfillmentType::Release->value,
        ]);

        if ($main !== null) {
            $main->recalculateProductSummary();
        }

        Notification::make()
            ->title('Het afroepverzoek is geplaatst.')
            ->success()
            ->send();

        if ($this->returnToOrderId !== null) {
            $this->redirect(
                route('filament.app.resources.mains.view', ['record' => $this->returnToOrderId]) . '?tab=purchase',
                navigate: false
            );
            return;
        }
        $this->redirect(ReleaseOrderResource::getUrl('index'));
    }

    public function placeReleaseOrderWithEmail(array $data): void
    {
        $main = $this->record->main_id !== null ? Main::find($this->record->main_id) : null;
        if ($this->record->getReferenceNumber() === 'concept' || $this->record->getStatus() === ReleaseOrderStatus::Initial) {
            $referenceNumber = $this->generateReferenceNumber($main);
            $this->record->setReferenceNumber($referenceNumber);
            $this->record->save();
        }

        $this->save(false, false);
        $this->record->refresh();

        $attachments = ApproveReleaseOrderEmailAction::resolveAttachments(
            $this->record,
            $data['attachments'] ?? []
        );

        $ref = $this->record->getReferenceNumber();
        $safeRef = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $ref);
        $pdfFilename = 'Afroepbon_' . $safeRef . '.pdf';
        $pdfContent = $this->generateReleaseOrderPdfContent();
        $tempPath = null;

        if ($pdfContent !== null && $pdfContent !== '') {
            try {
                $this->record->addMediaFromString($pdfContent)
                    ->usingFileName($pdfFilename)
                    ->toMediaCollection('documents');
            } catch (\Throwable $e) {
                report($e);
                Notification::make()
                    ->title('Afroepbon staat niet bij documenten')
                    ->body('De e-mail is wel voorbereid; het PDF-bestand kon niet aan de release order-documenten worden toegevoegd.')
                    ->warning()
                    ->send();
            }

            $dir = storage_path('app/temp');
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $tempPath = $dir.'/'.$pdfFilename;
            file_put_contents($tempPath, $pdfContent);
            array_unshift($attachments, [
                'path' => $tempPath,
                'name' => $pdfFilename,
                'mime' => 'application/pdf',
            ]);
        }

        $to = $data['to'] ?? [];
        $cc = $data['cc'] ?? [];
        $bcc = $data['bcc'] ?? [];

        app(SendReleaseOrderConfirmMailAction::class)->execute(
            releaseOrder: $this->record,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            subject: $data['subject'] ?? '',
            message: $data['message'] ?? '',
            attachments: $attachments
        );

        if ($tempPath !== null && is_file($tempPath)) {
            @unlink($tempPath);
        }

        $this->placeReleaseOrder();
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
            if (! $file) {
                continue;
            }
            $mime = $file->getMimeType();
            if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
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

        $this->mergeNewUploadedAttachmentsIntoReleaseOrderEmailModal($newMediaIds);

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

    /**
     * @param  array<int, string>  $newMediaIds
     */
    protected function mergeNewUploadedAttachmentsIntoReleaseOrderEmailModal(array $newMediaIds): void
    {
        if ($newMediaIds === [] || empty($this->mountedActions)) {
            return;
        }

        $index = null;
        foreach ($this->mountedActions as $key => $mounted) {
            if (! is_array($mounted)) {
                continue;
            }
            if (($mounted['name'] ?? null) === 'send_release_order_email') {
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

    /**
     * Build PDF bytes (same pattern as purchase order: addMediaFromString).
     */
    protected function generateReleaseOrderPdfContent(): ?string
    {
        $html = view('order.release_order', $this->record->getDocumentViewData())->render();

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

    protected function generateReferenceNumber(?Main $main): string
    {
        $uid = $main?->getUid() ?? '';
        $parts = explode('-', $uid, 2);
        $base = 'AFR-' . ($parts[1] ?? $uid);
        if ($base === 'AFR-' || $base === 'AFR') {
            $base = 'AFR-' . now()->format('Y-m-d-His');
        }
        $mainId = $this->record->main_id;
        if ($mainId === null) {
            return $base;
        }

        $recordId = $this->record->getId();
        $existsInDb = $recordId && ReleaseOrder::where('main_id', $mainId)->where('id', $recordId)->exists();

        if ($existsInDb) {
            /* Record already persisted (e.g. draft): this is the n-th release order for this main (ordered by id). */
            $n = ReleaseOrder::where('main_id', $mainId)->where('id', '<=', $recordId)->count();
            return $n <= 1 ? $base : $base . '-' . ($n - 1);
        }

        /* New unsaved record: suffix index equals count of existing rows for this main. */
        $totalExisting = ReleaseOrder::where('main_id', $mainId)->count();
        return $totalExisting === 0 ? $base : $base . '-' . $totalExisting;
    }

    public function addOrderProduct(array $data): void
    {
        $orderProductId = $data['orderProductId'] ?? null;
        if (!$orderProductId) {
            return;
        }

        $orderProduct = OrderProduct::find($orderProductId);
        if (!$orderProduct) {
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

    public function loadOrderProduct(Get $get, Set $set): void
    {
        $product = Product::find($get('product_id'));
        if (!$product) {
            return;
        }

        $this->syncOrderProductRepeaterProductFlags($set, $product);

        $salesPriceBase = round((float)($product->getCompanySalesPrice() ?? 0), 2);
        $orderProductId = $get('id');
        $quoteId = $this->record->quote_id;
        $orderId = $this->record->order_id;

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
                'order_id' => $orderId,
                'release_order_id' => $this->record->getId(),
            ]);
            $orderProduct->setReleaseOrderId($this->record->getId());
            if ($this->record->getStatus() !== ReleaseOrderStatus::Initial) {
                $orderProduct->setFulfillmentType(FulfillmentType::Release);
            } else {
                $orderProduct->setFulfillmentTypeBasedOnProduct();
            }
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
        $repeater->state(array_filter($state, fn($item) => ($item['id'] ?? 0) !== 0));
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

        if ($this->orderProducts->isEmpty() && ! $this->releaseOrderIsOrphaned) {
            $repeater = $this->form->getComponent('order_products');
            if ($repeater !== null) {
                $repeater->state([[
                    'qty' => 1,
                    'id' => 0,
                    'product_id' => null,
                    'attribute_summary_basic' => '',
                ]]);
            }
        }
    }

    protected function getFormActions(): array
    {
        if ($this->releaseOrderIsOrphaned) {
            return [
                $this->getCancelFormAction()
                    ->label('Terug')
                    ->action(function (): void {
                        if ($this->returnToOrderId !== null) {
                            $this->redirect(
                                route('filament.app.resources.mains.view', ['record' => $this->returnToOrderId]) . '?tab=purchase',
                                navigate: false
                            );

                            return;
                        }
                        $this->redirect(ReleaseOrderResource::getUrl('index'));
                    })
                    ->extraAttributes(['class' => 'white']),
            ];
        }

        return [
            PreviewReleaseOrderAction::make('preview_release_order'),
            ApproveReleaseOrderEmailAction::make('send_release_order_email')->label('Verzenden'),
            $this->getCancelFormAction()
                ->action(function (): void {
                    if ($this->returnToOrderId !== null) {
                        $this->redirect(
                            route('filament.app.resources.mains.view', ['record' => $this->returnToOrderId]) . '?tab=purchase',
                            navigate: false
                        );
                        return;
                    }
                    $this->redirect(ReleaseOrderResource::getUrl('index'));
                })
                ->extraAttributes(['class' => 'white']),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $title = 'Nieuw afroepverzoek';
        if ($this->record->getStatus() !== ReleaseOrderStatus::Initial && $this->record->getReferenceNumber() !== 'concept') {
            $title = 'Afroepverzoek: #' . $this->record->getReferenceNumber();
        }

        $main = $this->record->main;
        $quote = $main?->getNewestApprovedQuote();
        $releaseOrderProductIds = $quote !== null
            ? $quote->orderProducts()
                ->whereNotNull('product_id')
                ->pluck('product_id')
                ->map(static fn (mixed $productId): int => (int) $productId)
                ->unique()
                ->values()
                ->all()
            : [];

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb release-order-edit'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => $this->getBackToTitle(),
                        'url' => $this->getBackToUrl(),
                    ]),

                View::make('filament.resources.release-orders.partials.orphaned-release-order-notice')
                    ->viewData(fn (): array => [
                        'backUrl' => $this->getBackToUrl(),
                    ])
                    ->visible(fn (): bool => $this->releaseOrderIsOrphaned),

                Section::make($title)
                    ->columns(2)
                    ->disabled(fn (): bool => $this->releaseOrderIsOrphaned)
                    ->schema([
                        Group::make()
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                        Select::make('dealer_id')
                            ->label('Dealer')
                            ->relationship(
                                'dealer',
                                'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->where('type', CustomerType::Dealer->value)
                            ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->getName() ?? $record->getDescriptor())
                            ->extraAttributes(['class' => 'diffSelect'])
                            ->extraFieldWrapperAttributes(['class' => 'ter-attentie-van-field'])
                            ->searchable()
                            ->preload()
                            ->disabled()
                            ->columnSpanFull()
                            ->selectablePlaceholder(false),

                        TextInput::make('contactperson')
                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                            ->label('Adviseur dealer'),
]),
                        Grid::make(2)
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'borderTop address-block'])
                            ->schema([

                                Section::make()
                                    ->hiddenLabel()
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->schema([
                                        Select::make('shipping_address_type')
                                            ->label('Leveradres')
                                            ->extraFieldWrapperAttributes(['class'=>'custom-address-type-select'])
                                            ->inlineLabel()
                                            ->selectablePlaceholder(false)
                                            ->options(fn(Get $get) => $this->getDeliveryAddressTypeOptions($get))
                                            ->default(fn() => $this->getInitialDeliveryAddressTypeKey())
                                            ->extraAttributes(['class' => 'diffSelect address-type-select'])
                                            ->columnSpanFull()
                                            ->live()
                                            ->afterStateUpdated(fn(Set $set, $state) => $this->syncDeliveryAddressFromType($set, $state)),

                                        View::make('filament.resources.release-orders.partials.delivery-address-preview')
                                            ->visible(fn(Get $get) => ($get('shipping_address_type') ?? '') !== 'custom')
                                            ->columnSpanFull(),

                                        Group::make()
                                            ->visible(fn(Get $get) => ($get('shipping_address_type') ?? '') === 'custom')
                                            ->extraAttributes(fn(Get $get) => [
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
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('value'),
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
                                    ->restrictToProductIds($releaseOrderProductIds)
                                    ->extraAttributes(['class' => 'input-value'])
                                    ->extraFieldWrapperAttributes(['class' => 'product-select'])
                                    ->disabled()
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
                                    ->readOnly()
                                    ->formatStateUsing(fn($state) => number_format((float)$state, 2, '.', ''))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->extraInputAttributes(['disabled' => 'disabled']),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->columnSpanFull(),

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

    private function getDeliveryAddressTypeOptions(Get $get): array
    {
        $options = ['rd' => 'RD Mobility'];

        $main = $this->record->main;
        if ($main?->customer_id) {
            $options['customer'] = 'Klant';
        }
        if ($this->record->dealer_id) {
            $options['dealer'] = 'Dealer';
        }

        return $options;
    }

    private function getInitialDeliveryAddressTypeKey(): string
    {
        $additional = $this->record->getAdditional() ?? [];
        if (! empty($additional['shipping_address_type_key'])) {
            return (string) $additional['shipping_address_type_key'];
        }

        return 'rd';
    }

    public function getReleaseOrderLeveradresPreviewHtml(string $typeKey): string
    {
        if ($typeKey === 'custom') {
            return '';
        }

        $address = null;

        if ($typeKey === 'rd') {
            $rd = Customer::getRdMobilityCustomer();
            $rd->loadMissing(['billingAddress.customer', 'billingAddress.country']);
            $address = $rd->billingAddress;
        } elseif ($typeKey === 'customer') {
            $main = $this->record->main?->loadMissing([
                'customer.shippingAddress',
                'customer.billingAddress',
                'customer.address',
            ]);
            $address = $main?->customer?->getPhysicalDeliveryAddress();
            $address?->loadMissing(['customer', 'country']);
        } elseif ($typeKey === 'dealer') {
            $dealer = $this->record->dealer?->loadMissing([
                'shippingAddress',
                'billingAddress',
                'address',
            ]);
            $address = $dealer?->getPhysicalDeliveryAddress();
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
            $main = $this->record->main?->loadMissing([
                'customer.shippingAddress',
                'customer.billingAddress',
                'customer.address',
            ]);
            $customer = $main?->customer;
            $source = $customer?->getPhysicalDeliveryAddress();
            $name = $customer?->getName();
        } elseif ($key === 'dealer') {
            $dealer = $this->record->dealer?->loadMissing(['shippingAddress', 'billingAddress', 'address']);
            $source = $dealer?->getPhysicalDeliveryAddress();
            if ($dealer !== null) {
                $shipping = $dealer->shippingAddress;
                $name = trim((string) ($shipping?->getLocationName() ?? ''));
                if ($name === '') {
                    $name = trim((string) ($shipping?->getName() ?? ''));
                }
                if ($name === '') {
                    $name = $dealer->getName();
                }
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

        $result = $this->getDeliveryAddressForTypeKey((string)$key);
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
        $typeKey = $additional['shipping_address_type_key'] ?? $this->getInitialDeliveryAddressTypeKey();
        $data['shipping_address_type'] = $typeKey;
        $data['additional'] = array_merge($data['additional'] ?? [], [
            'shipping_name' => $additional['shipping_name'] ?? null,
            'delivery_address' => $additional['delivery_address'] ?? null,
        ]);

        $deliveryAddr = $data['additional']['delivery_address'] ?? null;
        $hasAddress = $deliveryAddr && (($deliveryAddr['street'] ?? '') !== '' || ($deliveryAddr['city'] ?? '') !== '');
        if (!$hasAddress) {
            $result = $this->getDeliveryAddressForTypeKey($typeKey);
            $data['additional']['shipping_name'] = $result['name'];
            $data['additional']['delivery_address'] = $result['address'];
        }

        if (array_key_exists('contactperson', $additional)) {
            $data['contactperson'] = (string) ($additional['contactperson'] ?? '');
        } else {
            $fittingNote = $this->record->main?->getFittingNote();
            $data['contactperson'] = is_array($fittingNote)
                ? trim((string) ($fittingNote['advisor_dealer_name'] ?? ''))
                : '';
        }

        $this->form->fill($data);
    }
}

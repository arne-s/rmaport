<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Actions\SendInvoiceMailAction;
use App\Enums\InvoiceCaption;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTerms;
use App\Exceptions\OrderOutOfStockException;
use App\Exceptions\QuoteRevisionAlreadyStartedException;
use App\Filament\Forms\Components\EmailRecipientSelect;
use App\Filament\Concerns\DispatchesExactSyncToastPolling;
use App\Filament\Support\RecordLockNavigation;
use App\Filament\Resources\InvoiceResource\Actions\SubmitInvoiceEmailAction;
use App\Filament\Resources\OrderResource\Support\OrderCustomerMailRecipients;
use App\Support\DocumentUploadValidation;
use App\Support\FinancialDocumentSentLabel;
use App\Helpers\EmailHelper;
use App\Mail\CustomInvoiceMail;
use App\Models\MailSenderProfile;
use App\Models\Order\BaseOrder;
use App\Models\Order\Invoice;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\Order\Quote;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonInterface;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderDocsTableWidget extends TableWidget
{
    use DispatchesExactSyncToastPolling;
    use WithFileUploads;

    protected string $view = 'filament.resources.orders.widgets.order-docs-table-widget';

    public ?Model $record = null;

    public bool $showRmaPlaceholderButtons = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $documentFiles = [];

    public int $maxFileSizeKb = 10240;

    public static function canView(): bool
    {
        return true;
    }

    public function canManageFinancials(): bool
    {
        return auth()->user()?->can('manage financials') ?? false;
    }

    public function canGenerateInvoice(): bool
    {
        if (! $this->canManageFinancials()) {
            return false;
        }

        $main = $this->record;
        if (! $main instanceof Main) {
            return false;
        }

        if ($main->getInvoiceId() !== null) {
            return false;
        }

        $order = $main->getLatestOrderForInvoicing();
        if ($order === null) {
            return false;
        }

        if ($order->getStatus() !== OrderGeneralStatus::Sent) {
            return false;
        }

        if ($order instanceof \App\Models\Order\Order
            && $order->needDepositInvoice()
            && $main->getDepositInvoiceId() === null
        ) {
            return false;
        }

        return true;
    }

    public function canShowCreateInvoiceFromMainButton(): bool
    {
        if (! $this->canManageFinancials()) {
            return false;
        }

        $main = $this->record;
        if (! $main instanceof Main) {
            return false;
        }

        $order = $main->getLatestOrderForInvoicing();
        if (! $order instanceof \App\Models\Order\Order) {
            return false;
        }

        if ($order->needDepositInvoice() && $main->getDepositInvoiceId() === null) {
            return false;
        }

        return true;
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if (is_array($record)) {
            return (string) ($record[ArrayRecord::getKeyName()] ?? '');
        }

        return (string) $record->getKey();
    }

    /**
     * Override to handle array records - parent's mapWithKeys can call getKey() on records
     * in some code paths, so we do the keying ourselves.
     */
    public function getTableRecords(): \Illuminate\Contracts\Pagination\Paginator|\Illuminate\Contracts\Pagination\CursorPaginator|Collection
    {
        if (! $this->getTable()->hasQuery()) {
            if ($this->cachedTableRecords) {
                return $this->cachedTableRecords;
            }

            $records = $this->getTable()->evaluate($this->getTable()->getDataSource(), [
                'columnSearches' => fn (): array => $this->getTableColumnSearches(),
                'filters' => fn (): ?array => $this->tableFilters,
                'page' => fn (): int | string => $this->getTablePage(),
                'recordsPerPage' => fn (): int | string => $this->getTableRecordsPerPage(),
                'search' => fn (): ?string => $this->getTableSearch(),
                'sort' => fn (): array => [$this->getTableSortColumn(), $this->getTableSortDirection()],
                'sortColumn' => fn (): ?string => $this->getTableSortColumn(),
                'sortDirection' => fn (): ?string => $this->getTableSortDirection(),
            ]);

            $keyed = collect();
            foreach ($records as $key => $record) {
                $recordKey = $this->getTableRecordKey($record);
                $keyed[$recordKey] = $record;
            }

            $this->cachedTableRecords = $keyed;

            return $keyed;
        }

        return parent::getTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->records(fn (?string $sortColumn, ?string $sortDirection): Collection => $this->buildRecords($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('description')
                    ->label('Type')
                    ->view('filament.tables.columns.financial-doc-description')
                    ->sortable(),
                TextColumn::make('uid')
                    ->label('Nummer')
                    ->view('filament.tables.columns.financial-doc-number')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Datum')
                    ->sortable(),
                TextColumn::make('status_label')
                    ->label('Status')
                    ->view('filament.tables.columns.financial-doc-status')
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->label('Betalingsstatus')
                    ->view('filament.tables.columns.financial-doc-payment-status')
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->label('Verzonden')
                    ->view('filament.tables.columns.financial-doc-sent')
                    ->sortable(),
            ])
            ->recordActions([
                $this->makeQuoteApproveAction(),
                $this->makeQuoteEditAction(),
                $this->makeCancelQuoteAction(),
                Action::make('editQuote')
                    ->action(function (array $record): void {
                        $quote = Quote::withoutGlobalScopes()->find($record['_model_id']);
                        if ($quote === null) {
                            return;
                        }

                        $this->redirectToQuoteEditIfAllowed($quote);
                    })
                    ->visible(fn (array $record): bool => $record['_type'] === 'order' && $record['status_value'] === OrderGeneralStatus::Draft->value && $record['type_value'] !== 'order')
                    ->iconButton()
                    ->icon('heroicon-o-pencil-square')
                    ->label('Bewerken')
                    ->extraAttributes(['class' => 'fi-docs-action-icon fi-docs-action-primary']),
                Action::make('editOrder')
                    ->action(function (array $record): void {
                        $order = Order::withoutGlobalScopes()->find($record['_model_id']);
                        if ($order === null) {
                            return;
                        }

                        $isDraft = in_array($record['status_value'] ?? '', [
                            OrderGeneralStatus::Draft->value,
                        ], true);

                        if ($isDraft) {
                            $this->redirectToOrderEditIfAllowed($order);

                            return;
                        }

                        try {
                            $newOrder = $order->createNewRevision();
                            $this->flushCachedTableRecords();
                            $this->redirectToOrderEditIfAllowed($newOrder);
                        } catch (OrderOutOfStockException) {
                            Notification::make()
                                ->title('Artikelen niet op voorraad')
                                ->body('Eén of meer artikelen in de offerte zijn niet op voorraad en kunnen niet worden besteld.')
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (array $record): bool => $record['_type'] === 'order' && $record['type_value'] === 'order' && $record['status_value'] !== OrderGeneralStatus::Changed->value)
                    ->iconButton()
                    ->icon('heroicon-o-pencil-square')
                    ->label('Bewerken')
                    ->extraAttributes(['class' => 'fi-docs-action-icon fi-docs-action-primary']),
                $this->makeCreditAction(),
                $this->makeDownloadAction(),
                $this->makeDeleteUploadAction(),
            ])
            ->recordActionsColumnLabel('Acties')
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->striped(false)
            ->headerActions([])
            ->bulkActions([])
            ->selectable(false)
            ->searchable(false)
            ->emptyStateHeading('Geen documenten');
    }

    private function buildRecords(?string $sortColumn = null, ?string $sortDirection = null): Collection
    {
        $main = $this->record;
        if (!$main instanceof Main) {
            return collect();
        }

        $orderRecords = BaseOrder::withoutGlobalScopes()
            ->with(['main.orderEvents', 'billingCustomer', 'customer', 'media'])
            ->where('main_id', $main->getId())
            ->whereIn('type', [
                OrderType::Quote->value,
                OrderType::Order->value,
                OrderType::Invoice->value,
                OrderType::DepositInvoice->value,
                OrderType::CreditInvoice->value,
            ])
            ->where('status', '!=', OrderGeneralStatus::Initial)
            ->get()
            ->map(fn (BaseOrder $order) => $this->buildOrderRecord($order));

        $mediaRecords = $main->getMedia('financial_documents')
            ->map(fn (Media $media) => $this->buildMediaRecord($media));

        // Use base Collection to avoid Eloquent/MediaCollection calling getKey() on array items
        $merged = collect([...$orderRecords->values(), ...$mediaRecords->values()]);

        $sortKey = match ($sortColumn) {
            'created_at' => 'created_at_raw',
            'payment_status' => 'payment_status_raw',
            'sent_at' => 'sent_at_raw',
            default => $sortColumn,
        };

        if (filled($sortKey)) {
            $merged = $merged->sortBy($sortKey, SORT_REGULAR, $sortDirection === 'desc');
        } else {
            $merged = $merged->sortByDesc('created_at_raw');
        }

        return $merged->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderRecord(BaseOrder $order): array
    {
        $typeValue = $order->type instanceof \BackedEnum ? $order->type->value : (string) ($order->type ?? '');
        $statusValue = $order->resolveFinancialDocumentStatusValue();

        $description = match ($typeValue) {
            'quote' => 'Offerte',
            'order' => 'Order',
            'invoice' => 'Slotfactuur',
            'deposit_invoice' => 'Aanbetalingsfactuur',
            default => OrderType::tryFrom($typeValue)?->getLabel() ?? $typeValue,
        };

        if ($order->caption instanceof InvoiceCaption && $typeValue !== 'credit_invoice') {
            $description = $order->caption->getLabel();
        }

        $pdfCollection = match ($typeValue) {
            'quote' => 'quote',
            'order' => 'order',
            'deposit_invoice' => 'deposit_invoice',
            'credit_invoice' => 'credit_invoice',
            'invoice' => 'invoice',
            default => null,
        };
        $invoicePdfUrl = $pdfCollection ? ($order->getFirstMediaUrl($pdfCollection) ?: null) : null;

        $sentDisplay = FinancialDocumentSentLabel::resolve($order);

        return [
            ArrayRecord::getKeyName() => 'bo_' . $order->getId(),
            '_type' => 'order',
            '_model_id' => $order->getId(),
            'description' => $description,
            'uid' => ($typeValue === 'order'
                ? $order->getUidFormattedWithRevision()
                : $order->getUidFormatted()) ?: '-',
            'created_at' => $this->formatDocumentDate($order->created_at),
            'created_at_raw' => $order->created_at,
            'type_value' => $typeValue,
            'status_value' => $statusValue,
            'status_label' => $statusValue,
            'payment_status' => $this->formatDocumentDate($order->paid_at),
            'payment_status_raw' => $order->paid_at,
            'payment_method_raw' => $order->payment_method,
            'payment_method_label' => $order->payment_method !== null
                ? (PaymentMethodType::tryFrom($order->payment_method instanceof \BackedEnum ? $order->payment_method->value : (string) $order->payment_method)?->getLabel())
                : null,
            'sent_at' => $sentDisplay['label'],
            'sent_at_raw' => $order->sent_at ?? $sentDisplay['scheduled_at'],
            'sent_at_scheduled_at' => $sentDisplay['scheduled_at'],
            '_order_type' => $typeValue,
            '_is_test' => (bool) $order->getIsTest(),
            '_invoice_pdf_url' => $invoicePdfUrl,
            'file_name' => null,
            'media_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMediaRecord(Media $media): array
    {
        $isPurchaseInvoice = ! empty($media->getCustomProperty('purchase_order_invoice_id'));
        $entryDate = $media->getCustomProperty('entry_date');
        $invoiceNumber = $media->getCustomProperty('invoice_number');

        return [
            ArrayRecord::getKeyName() => 'm_' . $media->id,
            '_type' => 'upload',
            '_model_id' => $media->id,
            'description' => $this->financialDocumentDescription($media),
            'uid' => $isPurchaseInvoice && $invoiceNumber ? $invoiceNumber : '-',
            'created_at' => $this->formatDocumentDate($media->created_at),
            'created_at_raw' => $media->created_at,
            'type_value' => 'upload',
            'status_value' => '',
            'status_label' => '',
            'payment_status' => '-',
            'payment_status_raw' => null,
            'payment_method_raw' => null,
            'payment_method_label' => null,
            'sent_at' => $isPurchaseInvoice && $entryDate
                ? $this->formatDocumentDate(\Carbon\Carbon::parse($entryDate))
                : '-',
            'sent_at_raw' => $isPurchaseInvoice && $entryDate
                ? \Carbon\Carbon::parse($entryDate)
                : null,
            '_order_type' => null,
            '_is_test' => false,
            'file_name' => $media->file_name,
            'media_id' => $media->id,
        ];
    }

    private function financialDocumentDescription(Media $media): string
    {
        $isPurchaseInvoice = ! empty($media->getCustomProperty('purchase_order_invoice_id'));

        if ($isPurchaseInvoice) {
            return (string) ($media->name ?: $media->file_name);
        }

        return (string) ($media->file_name ?: $media->name);
    }

    private function formatDocumentDate(?CarbonInterface $date): string
    {
        return $date !== null ? $date->translatedFormat('j M Y') : '-';
    }

    /**
     * Preview carousel items for the global open-document modal (same order as the table).
     *
     * @return list<array{key: string, title: string, orderId?: string, mediaId?: string, quotePreview?: bool, invoicePreview?: bool, orderHtmlPreview?: bool, downloadUrl?: string|null}>
     */
    public function getFinancialDocumentPreviewItems(): array
    {
        $items = [];

        foreach ($this->getTableRecords() as $record) {
            if (! is_array($record)) {
                continue;
            }

            $item = $this->previewItemForFinancialDocumentRecord($record);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{key: string, title: string, orderId?: string, mediaId?: string, quotePreview?: bool, invoicePreview?: bool, orderHtmlPreview?: bool, downloadUrl?: string|null}|null
     */
    private function previewItemForFinancialDocumentRecord(array $record): ?array
    {
        $key = (string) ($record[ArrayRecord::getKeyName()] ?? '');
        $title = (string) ($record['description'] ?? '-');

        if (($record['_type'] ?? '') === 'upload') {
            $mediaId = $record['media_id'] ?? null;

            if ($mediaId === null) {
                return null;
            }

            return [
                'key' => $key,
                'title' => $title,
                'mediaId' => (string) $mediaId,
                'downloadUrl' => route('documents.media-download', ['id' => $mediaId]),
            ];
        }

        if (($record['_type'] ?? '') !== 'order') {
            return null;
        }

        $typeValue = (string) ($record['type_value'] ?? '');
        $modelId = (string) ($record['_model_id'] ?? '');

        if ($modelId === '' || $modelId === '0') {
            return null;
        }

        $downloadUrl = match ($typeValue) {
            'invoice', 'deposit_invoice', 'credit_invoice' => route('documents.invoice-download', ['id' => $modelId]),
            'quote', 'order' => route('documents.order-pdf-download', ['id' => $modelId]),
            default => null,
        };

        return [
            'key' => $key,
            'title' => $title,
            'orderId' => $modelId,
            'quotePreview' => $typeValue === 'quote',
            'invoicePreview' => in_array($typeValue, ['order', 'invoice', 'deposit_invoice', 'credit_invoice'], true),
            'downloadUrl' => $downloadUrl,
        ];
    }

    private function makeQuoteApproveAction(): Action
    {
        return Action::make('quote_approve')
            ->requiresConfirmation()
            ->modalHeading('Statuswijziging: offerte akkoord')
            ->modalDescription('De status zal wijzigen naar "Order: Concept". De afdeling administratie wordt geïnformeerd.')
            ->modalSubmitActionLabel('Akkoord')
            ->iconButton()
            ->icon('heroicon-o-check-circle')
            ->label('Goedkeuren')
            ->extraAttributes(['class' => 'fi-docs-action-icon fi-docs-action-success'])
            ->visible(fn (array $record): bool => $record['_type'] === 'order'
                && $record['type_value'] === 'quote'
                && in_array($record['status_value'], [OrderGeneralStatus::Pending->value, OrderGeneralStatus::Sent->value], true))
            ->action(function (array $record): void {
                $model = $this->resolveQuoteOrOrder($record);
                if ($model === null) {
                    return;
                }
                try {
                    $order = $model instanceof Quote
                        ? $model->acceptQuote()
                        : $model->acceptOrder();

                    Notification::make()
                        ->title('De offerte is goedgekeurd')
                        ->body('Order #' . $order->getUidFormatted() . ' is aangemaakt.')
                        ->success()
                        ->send();

                    $this->flushCachedTableRecords();
                } catch (OrderOutOfStockException) {
                    Notification::make()
                        ->title('Artikelen niet op voorraad')
                        ->body('Eén of meer Artikelen in de offerte zijn niet op voorraad en kunnen niet worden besteld.')
                        ->danger()
                        ->send();
                }
            })
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function makeQuoteEditAction(): Action
    {
        return Action::make('quote_edit')
            ->requiresConfirmation()
            ->button()
            ->label(fn (array $record): string => $record['status_value'] === OrderGeneralStatus::Expired->value ? 'Herzien' : '')
            ->modalHeading(fn (array $record): string => $record['status_value'] === OrderGeneralStatus::Expired->value ? 'Herzien' : 'Aanpassen')
            ->extraAttributes(fn (array $record): array => [
                'class' => $record['status_value'] === OrderGeneralStatus::Expired->value ? 'button-red' : 'button-blue',
            ])
            ->visible(fn (array $record): bool => $record['_type'] === 'order'
                && $record['type_value'] === 'quote'
                && in_array($record['status_value'], [OrderGeneralStatus::Expired->value, OrderGeneralStatus::Pending->value, OrderGeneralStatus::Sent->value], true))
            ->action(function (array $record): void {
                $quote = Quote::withoutGlobalScopes()->find($record['_model_id']);
                if ($quote === null) {
                    return;
                }

                try {
                    $changedQuote = $quote->changeQuote();
                    $this->flushCachedTableRecords();
                    $this->redirectToQuoteEditIfAllowed($changedQuote);
                } catch (QuoteRevisionAlreadyStartedException $e) {
                    $this->handleQuoteRevisionAlreadyStarted($e);
                }
            })
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function makeCancelQuoteAction(): Action
    {
        return Action::make('cancel_quote')
            ->iconButton()
            ->icon('heroicon-s-x-mark')
            ->requiresConfirmation()
            ->label('Offerte annuleren')
            ->modalHeading('Aanvraag annuleren')
            ->modalDescription(new HtmlString('<div style="
                margin-top: .5rem;
                font-size: 13px;
                font-weight: 450;
                color: #000;
            ">
                De offerte wordt geannuleerd. De klant en de dealer (indien van toepassing) wordt geïnformeerd.
            </div>'))
            ->extraAttributes([
                'class' => 'deleteOrderAction',
                'style' => 'border: none; padding: 0 !important; color: red !important; margin: 0 !important;',
            ])
            ->schema([
                TextInput::make('cancel_comment')
                    ->label('Reden van annulering')
                    ->required()
                    ->maxLength(255),
            ])
            ->extraModalWindowAttributes(['class' => 'modalForm'])
            ->visible(fn (array $record): bool => $record['_type'] === 'order'
                && $record['type_value'] === 'quote'
                && in_array($record['status_value'], [OrderGeneralStatus::Pending->value, OrderGeneralStatus::Sent->value], true))
            ->action(function (array $data, array $record): void {
                $quote = Quote::withoutGlobalScopes()->find($record['_model_id']);
                if ($quote === null) {
                    return;
                }
                $quote->setStatus(OrderGeneralStatus::Cancelled);
                $quote->setCancelComment($data['cancel_comment']);
                $quote->save();

                Notification::make()
                    ->title('Offerte is geannuleerd.')
                    ->success()
                    ->send();

                $this->flushCachedTableRecords();
            })
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function makeDownloadAction(): Action
    {
        return Action::make('downloadFile')
            ->iconButton()
            ->label('Downloaden')
            ->color('gray')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (array $record): bool => ($record['_type'] ?? '') === 'upload' && filled($record['media_id'] ?? null))
            ->extraAttributes(['class' => 'fi-docs-action-icon fi-docs-action-primary'])
            ->action(function (array $record): ?StreamedResponse {
                return $this->downloadMediaFile((int) $record['media_id']);
            });
    }

    public function canShowCreateQuoteFromRequestButton(): bool
    {
        $main = $this->record;
        if (! $main instanceof Main) {
            return false;
        }

        $status = $main->getOrderStatus();
        if ($status !== OrderStatus::QuoteDraft && $status?->value !== OrderStatus::QuoteDraft->value) {
            return false;
        }

        return ! $main->hasQuoteOrOrderBeyondInitial();
    }

    public function canShowCreateOrderFromRequestButton(): bool
    {
        $main = $this->record;
        if (! $main instanceof Main) {
            return false;
        }

        $status = $main->getOrderStatus();
        $statusValue = $status?->value;
        $inDefaultQuoteOrderDraftStatuses = in_array($statusValue, [
            OrderStatus::QuoteDraft->value,
            OrderStatus::OrderDraft->value,
        ], true);

        $inServiceQuoteMainPhase = $main->getSubtype() === OrderSubtype::Service
            && $status instanceof OrderStatus
            && OrderStatus::getMainStatusFor($status) === OrderStatus::Quote;

        if (! $inDefaultQuoteOrderDraftStatuses && ! $inServiceQuoteMainPhase) {
            return false;
        }

        if ($main->getSubtype() === OrderSubtype::Unit) {
            return false;
        }

        return ! $main->shouldBlockDirectOrderCreationFromMainHeader();
    }

    public function redirectToCreateQuoteFromMain(): void
    {
        $main = $this->record;
        if (! $main instanceof Main) {
            return;
        }

        $this->redirect(route('filament.app.resources.quotes.from-main', ['main' => $main->getId()]));
    }

    public function redirectToCreateOrderFromMain(): void
    {
        $main = $this->record;
        if (! $main instanceof Main) {
            return;
        }

        $this->redirect(route('filament.app.resources.orders.from-main', ['main' => $main->getId()]));
    }

    public function create_invoiceAction(): Action
    {
        return $this->makeCreateInvoiceAction();
    }

    public function generate_invoiceAction(): Action
    {
        return $this->makeGenerateInvoiceAction();
    }

    private function makeCreateInvoiceAction(): Action
    {
        return Action::make('create_invoice')
            ->label('Factuur aanmaken')
            ->button()
            ->visible(fn (): bool => $this->canShowCreateInvoiceFromMainButton())
            ->action(function (): void {
                $main = $this->record;
                if (! $main instanceof Main) {
                    return;
                }

                $invoice = Invoice::withoutGlobalScopes()->create([
                    'type'                  => OrderType::Invoice->value,
                    'status'                => OrderGeneralStatus::Initial->value,
                    'main_id'               => $main->getId(),
                    'order_id'              => null,
                    'customer_id'           => $main->customer_id,
                    'billing_customer_id'   => $main->billing_customer_id,
                    'shipping_customer_id'  => $main->shipping_customer_id,
                    'customer_address_type' => $main->getCustomerAddressType(),
                    'payment_terms'         => PaymentTerms::Postpay->value,
                    'subtype'               => $main->getSubtype()?->value,
                ]);

                $this->redirect(route('filament.app.resources.invoices.edit-from-main', ['record' => $invoice->id]));
            });
    }

    private function makeGenerateInvoiceAction(): Action
    {
        return Action::make('generate_invoice')
            ->label('Factuur genereren')
            ->button()
            ->modalHeading('Factuur verzenden')
            ->closeModalByEscaping(false)
            ->visible(fn (): bool => $this->canGenerateInvoice())
            ->schema(function (): array {
                $main = $this->record;
                $customer = $main instanceof Main ? $main->customer : null;
                $billingCustomer = $main instanceof Main ? $main->billingCustomer : null;

                $recipientOptions = [];
                if ($main instanceof Main) {
                    $customerLabel = OrderCustomerMailRecipients::customerRecipientOptionLabel($main, null);
                    if ($customerLabel !== null) {
                        $recipientOptions['customer'] = $customerLabel;
                    }
                }
                if ($billingCustomer !== null) {
                    $recipientOptions['dealer'] = 'Dealer: ' . $billingCustomer->getName() . ' <' . ($billingCustomer->getEmail() ?: '—') . '>';
                }

                $defaultTo = [];
                $defaultCc = [];
                if ($main instanceof Main) {
                    $defaultTo = SubmitInvoiceEmailAction::defaultRecipientKeysForInvoice($main);
                    $defaultCc = SubmitInvoiceEmailAction::defaultCcRecipientKeysForInvoice($main);
                }

                $rawSubject = CustomInvoiceMail::getRawTemplateSubjectFromDatabase();
                $defaultSubject = $rawSubject !== '' ? $rawSubject : 'Factuur [invoice_number]';

                $rawMessage = CustomInvoiceMail::getRawTemplateContentFromDatabase();

                return [
                    Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

                    Group::make()
                        ->extraAttributes(['class' => 'custom-form-design', 'style' => 'margin-top: -25px'])
                        ->schema([
                            TextInput::make('from')
                                ->label('Vanaf')
                                ->required()
                                ->disabled()
                                ->default(fn (): string => MailSenderProfile::modalFromDisplayLabel('invoices')),

                            EmailRecipientSelect::make('to')
                                ->label('To')
                                ->options($recipientOptions)
                                ->default($defaultTo)
                                ->columnSpanFull(),

                            EmailRecipientSelect::make('cc')
                                ->label('CC')
                                ->options($recipientOptions)
                                ->default($defaultCc)
                                ->columnSpanFull(),

                            EmailRecipientSelect::make('bcc')
                                ->label('BCC')
                                ->options($recipientOptions)
                                ->columnSpanFull(),

                            TextInput::make('subject')
                                ->label('Onderwerp')
                                ->required()
                                ->default($defaultSubject),
                        ]),

                    Section::make('Bericht')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            RichEditor::make('message')
                                ->hiddenLabel()
                                ->label('Bericht')
                                ->required()
                                ->default($rawMessage)
                                ->disableToolbarButtons(['attachFiles'])
                                ->columnSpanFull(),
                        ]),
                ];
            })
            ->action(function (array $data): void {
                $main = $this->record;
                if (! $main instanceof Main) {
                    return;
                }

                $lastOrder = $main->getLastOrder();
                if (! $lastOrder instanceof Order) {
                    Notification::make()
                        ->title('Geen order gevonden voor deze aanvraag')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $invoice = $lastOrder->createInvoice(sendSlotInvoiceMailImmediately: false);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Factuur aanmaken mislukt')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $main->setInvoiceId($invoice->getId());
                $main->saveQuietly();

                $toEmails = SubmitInvoiceEmailAction::resolveRecipientEmailsForInvoice($invoice, $data['to'] ?? []);
                if ($toEmails === []) {
                    Notification::make()
                        ->title('Minimaal één ontvanger (To) is verplicht')
                        ->danger()
                        ->send();

                    return;
                }

                $ccEmails = SubmitInvoiceEmailAction::resolveRecipientEmailsForInvoice($invoice, $data['cc'] ?? []);
                $bccEmails = SubmitInvoiceEmailAction::resolveRecipientEmailsForInvoice($invoice, $data['bcc'] ?? []);

                $emailData = [
                    'to' => $toEmails,
                    'cc' => $ccEmails,
                    'bcc' => $bccEmails,
                    'subject' => $data['subject'] ?? '',
                    'message' => $data['message'] ?? '',
                ];
                $emailData = SubmitInvoiceEmailAction::applyTemplateVariablesAfterPersist($invoice, $emailData);

                try {
                    app()->makeWith(SendInvoiceMailAction::class, ['invoice' => $invoice])
                        ->executeWithModalEmail(
                            to: $emailData['to'],
                            cc: $emailData['cc'],
                            bcc: $emailData['bcc'],
                            subject: (string) ($emailData['subject'] ?? ''),
                            message: (string) ($emailData['message'] ?? ''),
                        );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Verzenden mislukt')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $invoice->refresh();
                $invoice->setSentAt(now());
                $invoice->setStatus(OrderGeneralStatus::Sent);
                $invoice->saveQuietly();

                Notification::make()
                    ->title('Factuur verzonden')
                    ->body('Factuur #' . $invoice->getUidFormatted() . ' is verzonden.')
                    ->success()
                    ->send();

                if (config('exact.enabled')) {
                    $this->requestExactSyncToastPolling();
                }

                $this->flushCachedTableRecords();
            })
            ->modalSubmitActionLabel('Verzenden')
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function makeCreditAction(): Action
    {
        return Action::make('credit')
            ->label('Crediteren')
            ->extraAttributes(['class' => 'fi-docs-action-icon fi-docs-action-primary'])
            ->requiresConfirmation()
            ->modalHeading('Creditfactuur aanmaken')
            ->modalDescription('Er wordt een creditfactuur aangemaakt voor deze factuur.')
            ->modalSubmitActionLabel('Crediteren')
            ->visible(fn (array $record): bool => $this->canManageFinancials()
                && $record['_type'] === 'order'
                && in_array($record['type_value'], ['invoice', 'deposit_invoice'], true))
            ->action(function (array $record): void {
                $invoice = \App\Models\Order\Invoice::withoutGlobalScopes()->find($record['_model_id']);
                if ($invoice === null) {
                    return;
                }
                $credit = $invoice->createCreditInvoice();
                $this->redirect(route('filament.app.resources.credit-invoices.edit-from-main', ['record' => $credit->id]));
            })
            ->modalCancelAction(fn (Action $action) => $action->extraAttributes(['class' => 'white']));
    }

    private function makeDeleteUploadAction(): Action
    {
        return Action::make('deleteFile')
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Document verwijderen')
            ->visible(fn (array $record): bool => $record['_type'] === 'upload')
            ->action(function (array $record): void {
                $this->deleteMediaFile($record['media_id']);
            })
            ->extraAttributes(['class' => 'fi-docs-action-icon fi-docs-action-danger']);
    }

    private function resolveQuoteOrOrder(array $record): Quote|Order|null
    {
        $type = $record['_order_type'] ?? null;
        if ($type === 'quote') {
            return Quote::withoutGlobalScopes()->find($record['_model_id']);
        }
        if ($type === 'order') {
            return Order::withoutGlobalScopes()->find($record['_model_id']);
        }

        return null;
    }

    public function updatedDocumentFiles(): void
    {
        if (empty($this->documentFiles)) {
            return;
        }

        $allowedMimes = config('financial-docs.allowed_mime_types', []);
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:' . implode(',', $allowedMimes) : 'file';

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|' . $mimetypesRule . '|max:' . $this->maxFileSizeKb,
            ], DocumentUploadValidation::validationMessages($allowedMimes, $this->maxFileSizeKb));
        } catch (ValidationException $e) {
            $this->documentFiles = [];
            DocumentUploadValidation::sendInvalidUploadNotification($e, $allowedMimes, $this->maxFileSizeKb);

            return;
        }

        $main = $this->record;
        if (!$main instanceof Main) {
            $this->documentFiles = [];
            Notification::make()
                ->title('Model niet gevonden.')
                ->danger()
                ->send();

            return;
        }

        $count = 0;
        $rejected = [];

        foreach ($this->documentFiles as $file) {
            if (!$file) {
                continue;
            }

            $mime = $file->getMimeType();
            if (!in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $main->addMedia($file->getRealPath())
                ->usingFileName($originalName)
                ->usingName(pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName)
                ->toMediaCollection('financial_documents');
            $count++;
        }

        $this->documentFiles = [];
        $main->unsetRelation('media');

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
                ->title('Ongeldig bestand')
                ->body(DocumentUploadValidation::allowedTypesDescription($allowedMimes) . ' Overgeslagen: ' . $names)
                ->danger()
                ->send();
        }

        $this->flushCachedTableRecords();
    }

    public function downloadMediaFile(int $mediaId): ?StreamedResponse
    {
        $main = $this->record;
        if (!$main instanceof Main) {
            return null;
        }

        $media = $main->getMedia('financial_documents')->firstWhere('id', $mediaId);
        if ($media === null) {
            return null;
        }

        $relativePath = $media->getPathRelativeToRoot();
        if (!Storage::disk($media->disk)->exists($relativePath)) {
            return null;
        }

        $filename = $media->file_name
            ?: ($media->name ? $media->name . '.' . $media->extension : 'document-' . $media->id . '.' . $media->extension);

        return Storage::disk($media->disk)->download($relativePath, $filename);
    }

    public function deleteMediaFile(int $mediaId): void
    {
        $main = $this->record;
        if (!$main instanceof Main) {
            return;
        }

        $media = $main->getMedia('financial_documents')->firstWhere('id', $mediaId);
        if ($media === null) {
            return;
        }

        $media->delete();
        $main->unsetRelation('media');

        Notification::make()
            ->title('Document verwijderd.')
            ->success()
            ->send();

        $this->flushCachedTableRecords();
    }

    private function redirectToQuoteEditIfAllowed(Quote $quote): void
    {
        RecordLockNavigation::attemptRedirectToEdit(
            $this,
            $quote,
            route('filament.app.resources.quotes.edit', ['record' => $quote->getId()]),
        );
    }

    private function redirectToOrderEditIfAllowed(Order $order): void
    {
        RecordLockNavigation::attemptRedirectToEdit(
            $this,
            $order,
            route('filament.app.resources.orders.edit', ['record' => $order->getId()]),
        );
    }

    private function handleQuoteRevisionAlreadyStarted(QuoteRevisionAlreadyStartedException $exception): void
    {
        RecordLockNavigation::notifyRevisionAlreadyStarted('offerte', $exception->startedByUserName);
        $this->flushCachedTableRecords();
    }

}

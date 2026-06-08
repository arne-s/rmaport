<?php

namespace App\Filament\Resources\MarginOverviewResource\Pages;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Enums\FiltersLayout;
use App\Enums\OrderType;
use App\Enums\PurchaseInvoiceRowType;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\MarginOverviewResource;
use App\Filament\Resources\PurchaseOrderInvoiceResource\Actions\LinkPurchaseOrderAction;
use App\Filament\Support\ImportExportAuthorization;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use Illuminate\Contracts\View\View;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use App\Filament\Tables\Columns\{
    OrderMarginsColumn,
    OrderNumberPageColumn,
    PaidColumn,
    PurchaseOrderInvoiceColumn,
    PurchaseOrderNumberColumn,
};
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListMarginOverview extends ListRecords
{
    protected static string $resource = MarginOverviewResource::class;

    protected static ?string $title = 'Marge orders';

    protected static ?string $breadcrumb = 'Marge orders';

    public ?int $linkingPurchaseOrderInvoiceId = null;

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.margin-overview.index') => 'Marge orders',
        ];
    }

    protected function getTableQuery(): Builder
    {
        return BaseOrder::query()
            ->select('orders.*')
            ->where('orders.type', OrderType::Order)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('orders as margin_mains')
                    ->where('margin_mains.type', OrderType::Main->value)
                    ->whereNotNull('margin_mains.order_id')
                    ->whereColumn('margin_mains.order_id', 'orders.id');
            })
            ->leftJoin('orders as margin_request_main', function ($join): void {
                $join->on('margin_request_main.id', '=', 'orders.main_id')
                    ->where('margin_request_main.type', OrderType::Main->value);
            })
            ->leftJoin('purchase_orders', function ($join): void {
                $join->whereRaw(
                    '(purchase_orders.order_id = orders.id
                    OR (orders.main_id IS NOT NULL AND purchase_orders.main_id = orders.main_id)
                    OR purchase_orders.main_id IN (
                        SELECT mm.id FROM orders AS mm
                        WHERE mm.type = ? AND mm.order_id = orders.id
                    ))
                    AND purchase_orders.status != ?',
                    [OrderType::Main->value, PurchaseOrderStatus::Initial->value],
                );
            })
            ->leftJoin('purchase_order_invoices', function ($join): void {
                $join->on('purchase_order_invoices.orderable_id', '=', 'purchase_orders.id')
                    ->where('purchase_order_invoices.orderable_type', '=', PurchaseOrder::class);
            })
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id');
    }

    public function openLinkPurchaseOrderModal(int|string $invoiceId): void
    {
        $this->linkingPurchaseOrderInvoiceId = (int) $invoiceId;

        $this->mountAction('linkPurchaseOrder');
    }

    public function linkPurchaseOrderAction(): Action
    {
        return LinkPurchaseOrderAction::make();
    }

    public function getTableRecords(): Collection
    {
        $records = parent::getTableRecords()
            ->unique('id');
        $result = Collection::empty();

        foreach ($records as $order) {
            /** @var BaseOrder $order */
            $order->admin_margin_summary = $order->getSpMarginSummaryAttribute();
            $order->companySalesPrice = $order->getCompanySalesPriceTotal();

            $mainIdForPurchaseOrders = $order->main_id
                ?? Main::query()->where('order_id', $order->getKey())->value('id');

            $purchaseOrders = PurchaseOrder::query()
                ->excludingInitialStatus()
                ->where(function (Builder $q) use ($order, $mainIdForPurchaseOrders): void {
                    $q->where('purchase_orders.order_id', $order->getKey());
                    if ($mainIdForPurchaseOrders !== null) {
                        $q->orWhere('purchase_orders.main_id', $mainIdForPurchaseOrders);
                    }
                })
                ->with(['purchaseOrderInvoices', 'supplier'])
                ->orderBy('purchase_orders.id')
                ->get()
                ->unique('id')
                ->values();

            foreach ($purchaseOrders as $purchaseOrder) {
                if ($purchaseOrder->getOrderId() === null
                    || ($mainIdForPurchaseOrders !== null
                        && (int) $purchaseOrder->getMainId() === (int) $mainIdForPurchaseOrders)) {
                    $purchaseOrder->setRelation('order', $order);
                }
            }

            $order->setRelation('purchaseOrders', $purchaseOrders);

            $poCount = $purchaseOrders->count();

            if ($poCount === 0) {
                $order->_rowType = PurchaseInvoiceRowType::OrderRow;
                $order->_key = "order-{$order->id}";
                $result->push($order);

                continue;
            }

            if ($poCount > 1) {
                $this->pushParentOrderRow($result, $order, $purchaseOrders);
                foreach ($purchaseOrders as $purchaseOrder) {
                    $this->pushPurchaseOrderChildRows($result, $purchaseOrder);
                }

                continue;
            }

            $purchaseOrder = $purchaseOrders->first();
            $poInvoices = $purchaseOrder->purchaseOrderInvoices;

            if ($poInvoices->count() > 1) {
                $this->pushParentOrderRow($result, $order, $purchaseOrders);
                foreach ($poInvoices as $invoice) {
                    $this->pushInvoiceChildRow($result, $invoice, $purchaseOrder);
                }

                continue;
            }

            if ($poInvoices->isNotEmpty()) {
                $invoice = $poInvoices->first();
                $this->applyInvoiceFieldsToOrder($order, $invoice, $purchaseOrder);
                $order->setRelation('purchaseOrders', $purchaseOrders);
                $order->_rowType = PurchaseInvoiceRowType::InvoiceRow;
                $order->_key = "invoice-{$order->id}";
                $result->push($order);

                continue;
            }

            $order->purchaseOrder = $purchaseOrder;
            $order->_rowType = PurchaseInvoiceRowType::OrderRow;
            $order->_key = "order-{$order->id}";
            $priceTotals = $purchaseOrder->priceTotals;
            $order->company_purchase_price_total = $priceTotals['companyPurchasePrice'] ?? 0;
            $result->push($order);
        }

        return $result;
    }

    /**
     * @param  Collection<int, Model>  $result
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     */
    private function pushParentOrderRow(Collection $result, BaseOrder $order, Collection $purchaseOrders): void
    {
        $hasAnyInvoice = $purchaseOrders->flatMap(
            fn (PurchaseOrder $purchaseOrder): Collection => $purchaseOrder->purchaseOrderInvoices,
        )->isNotEmpty();

        $order->company_purchase_price_total = $purchaseOrders->sum(
            fn (PurchaseOrder $purchaseOrder): float => (float) (($purchaseOrder->priceTotals['companyPurchasePrice'] ?? 0)),
        );

        if ($hasAnyInvoice) {
            $order->total_amount = $purchaseOrders->flatMap(
                fn (PurchaseOrder $purchaseOrder): Collection => $purchaseOrder->purchaseOrderInvoices,
            )->sum(fn (PurchaseOrderInvoice $invoice): float => abs((float) $invoice->amount));
            $order->price_cost_margin_summary = $this->getOrderPriceCostMarginSummary($order);
            $order->_rowType = PurchaseInvoiceRowType::InvoiceRowParent;
        } else {
            $order->total_amount = null;
            $order->price_cost_margin_summary = null;
            $order->_rowType = PurchaseInvoiceRowType::OrderRowParent;
        }

        $order->_key = "parent-{$order->id}";
        $result->push($order);
    }

    /**
     * @param  Collection<int, Model>  $result
     */
    private function pushPurchaseOrderChildRows(Collection $result, PurchaseOrder $purchaseOrder): void
    {
        $poInvoices = $purchaseOrder->purchaseOrderInvoices;

        if ($poInvoices->isNotEmpty()) {
            foreach ($poInvoices as $invoice) {
                $this->pushInvoiceChildRow($result, $invoice, $purchaseOrder);
            }

            return;
        }

        $row = new Order;
        $row->admin_margin_summary = $purchaseOrder->admin_margin_summary;
        $priceTotals = $purchaseOrder->priceTotals;
        $row->companySalesPrice = $priceTotals['companySalesPrice'] ?? 0;
        $row->company_purchase_price_total = $priceTotals['companyPurchasePrice'] ?? 0;
        $row->purchaseOrder = $purchaseOrder;
        $row->_rowType = PurchaseInvoiceRowType::OrderRowChild;
        $row->_key = "child-po-{$purchaseOrder->getId()}";
        $row->total_amount = null;
        $row->price_cost_margin_summary = null;

        $result->push($row);
    }

    /**
     * @param  Collection<int, Model>  $result
     */
    private function pushInvoiceChildRow(Collection $result, PurchaseOrderInvoice $invoice, PurchaseOrder $purchaseOrder): void
    {
        $invoice->admin_margin_summary = $purchaseOrder->admin_margin_summary;
        $priceTotals = $purchaseOrder->priceTotals;
        $invoice->companySalesPrice = $priceTotals['companySalesPrice'] ?? 0;
        $invoice->company_purchase_price_total = $priceTotals['companyPurchasePrice'] ?? 0;
        $invoice->total_amount = abs((float) $invoice->amount);
        $invoice->setRelation('purchaseOrder', $purchaseOrder);

        $orderStubForInvoiceMargin = new Order;
        $orderStubForInvoiceMargin->companySalesPrice = $invoice->companySalesPrice;
        $orderStubForInvoiceMargin->total_amount = $invoice->total_amount;
        $invoice->price_cost_margin_summary = $this->getOrderPriceCostMarginSummary($orderStubForInvoiceMargin);

        $invoice->_rowType = PurchaseInvoiceRowType::InvoiceRowChild;
        $invoice->_key = "child-inv-{$invoice->getKey()}";

        $result->push($invoice);
    }

    private function applyInvoiceFieldsToOrder(BaseOrder $order, PurchaseOrderInvoice $invoice, PurchaseOrder $purchaseOrder): void
    {
        $priceTotals = $purchaseOrder->priceTotals;

        $order->purchase_invoice_id = $invoice->getKey();
        $order->invoice_number = $invoice->invoice_number;
        $order->paid_at = $invoice->paid_at;
        $order->days_since_received = $invoice->days_since_received;
        $order->due_date = $invoice->due_date;
        $order->total_amount = abs((float) $invoice->amount);
        $order->email_received_at = $invoice->email_received_at;
        $order->purchaseOrder = $purchaseOrder;
        $order->company_purchase_price_total = $priceTotals['companyPurchasePrice'] ?? 0;

        $orderStubForInvoiceMargin = new Order;
        $orderStubForInvoiceMargin->companySalesPrice = $order->companySalesPrice;
        $orderStubForInvoiceMargin->total_amount = $order->total_amount;
        $order->price_cost_margin_summary = $this->getOrderPriceCostMarginSummary($orderStubForInvoiceMargin);
    }

    /**
     * Resolves the Main (request) row for a margin table record: prefer order.main_id, else Main linked by order_id,
     * else Main via purchase order’s sales order.
     */
    private function resolveMarginTableMain(Model $record): ?Main
    {
        if ($record instanceof PurchaseOrderInvoice) {
            $orderable = $record->orderable;
            $purchaseOrder = $orderable instanceof PurchaseOrder ? $orderable : null;

            $fromInvoice = $record->main_id !== null ? $record->main : null;

            return $fromInvoice
                ?? $purchaseOrder?->order?->main
                ?? ($purchaseOrder?->getOrderId() !== null
                    ? Main::query()->where('order_id', $purchaseOrder->getOrderId())->first()
                    : null);
        }

        if ($record instanceof BaseOrder) {
            $fromBelongsTo = $record->main_id !== null ? $record->main : null;

            $fromOrderId = null;
            if ($record->getKey() !== null) {
                $fromOrderId = Main::query()->where('order_id', $record->getKey())->first();
            }

            return $fromBelongsTo
                ?? $fromOrderId
                ?? $record->purchaseOrder?->main
                ?? $record->purchaseOrder?->order?->main
                ?? ($record->purchaseOrder?->getOrderId() !== null
                    ? Main::query()->where('order_id', $record->purchaseOrder->getOrderId())->first()
                    : null);
        }

        return null;
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return $record->_key ?? '';
    }

    public function getOrderPriceCostMarginSummary(BaseOrder $record): string
    {
        $paymentAmount = floatval($record->total_amount ?? 0);
        $companySalesPrice = $record->companySalesPrice ?? 0;

        $margin = $companySalesPrice - $paymentAmount;
        $add = '';

        if ($paymentAmount > 0) {
            $percentage = ($margin / $paymentAmount) * 100;
            $add = ' (' . round($percentage, 1) . '%)';
        }

        return '€' . number_format((float) $margin, 2, ',', '.') . $add;
    }

    private function isParentMarginRowType(?PurchaseInvoiceRowType $rowType): bool
    {
        return in_array($rowType, [
            PurchaseInvoiceRowType::OrderRowParent,
            PurchaseInvoiceRowType::InvoiceRowParent,
        ], true);
    }

    private function isChildMarginRowType(?PurchaseInvoiceRowType $rowType): bool
    {
        return in_array($rowType, [
            PurchaseInvoiceRowType::OrderRowChild,
            PurchaseInvoiceRowType::InvoiceRowChild,
        ], true);
    }

    private function marginRowShowsLinkPurchaseOrder(Model $record): bool
    {
        return $record instanceof PurchaseOrderInvoice
            && ! $record->isLinkedToActivePurchaseOrder();
    }

    private function marginRowShowsInvoiceAgeAndDueDate(?PurchaseInvoiceRowType $rowType): bool
    {
        return ! in_array($rowType, [
            PurchaseInvoiceRowType::OrderRow,
            PurchaseInvoiceRowType::OrderRowParent,
            PurchaseInvoiceRowType::OrderRowChild,
            PurchaseInvoiceRowType::InvoiceRowParent,
        ], true);
    }

    private function marginRowShowsRequestMainUid(?PurchaseInvoiceRowType $rowType): bool
    {
        return ! $this->isChildMarginRowType($rowType);
    }

    private function resolveMarginTableSupplierName(Model $record): string
    {
        if ($this->isParentMarginRowType($record->_rowType ?? null)) {
            return '';
        }

        if ($record instanceof PurchaseOrderInvoice) {
            $purchaseOrder = $record->relationLoaded('purchaseOrder')
                ? $record->getRelation('purchaseOrder')
                : ($record->orderable instanceof PurchaseOrder ? $record->orderable : null);

            return (string) ($purchaseOrder?->supplier?->name ?? $record->supplier_name ?? '');
        }

        if ($record instanceof BaseOrder) {
            $purchaseOrder = $record->purchaseOrder ?? null;

            if ($purchaseOrder instanceof PurchaseOrder) {
                $purchaseOrder->loadMissing('supplier');

                return (string) ($purchaseOrder->supplier?->name ?? '');
            }
        }

        return '';
    }

    /**
     * Marge-modal alleen op de samenvattingsregel (parent) of op een enkele regel zonder subregels.
     */
    private function marginRowShowsOrderMargins(?PurchaseInvoiceRowType $rowType): bool
    {
        return in_array($rowType, [
            PurchaseInvoiceRowType::OrderRowParent,
            PurchaseInvoiceRowType::InvoiceRowParent,
            PurchaseInvoiceRowType::OrderRow,
            PurchaseInvoiceRowType::InvoiceRow,
        ], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_main_uid')
                    ->label('Aanvraagnummer')
                    ->state(function (Model $record): string {
                        $main = $this->resolveMarginTableMain($record);
                        $uid = $main?->getUid();
                        if ($main === null || ! filled($uid)) {
                            return '__margin_no_request_main__';
                        }

                        return json_encode([$main->getId(), $uid], JSON_THROW_ON_ERROR);
                    })
                    ->formatStateUsing(function (string $state, Model $record): HtmlString|string {
                        if (! $this->marginRowShowsRequestMainUid($record->_rowType ?? null)) {
                            return '';
                        }

                        if ($state === '__margin_no_request_main__') {
                            return '';
                        }
                        try {
                            $decoded = json_decode($state, true, 2, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            return '';
                        }
                        if (! is_array($decoded) || count($decoded) !== 2) {
                            return '';
                        }
                        [$mainId, $uid] = $decoded;
                        $url = route('filament.app.resources.mains.view', ['record' => (int) $mainId]);

                        return new HtmlString(
                            '<a class="main-request-number-link hover:underline" href="'.e($url).'">'.e($uid).'</a>',
                        );
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        'margin_request_main.uid',
                        'like',
                        '%'.$search.'%',
                    ))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';

                        return $query->orderBy('margin_request_main.uid', $dir);
                    }),

                OrderNumberPageColumn::make('purchaseOrderInvoice.orderUid')
                    ->label('Ordernummer')
                    ->empty(fn (Model $record): bool => $this->isChildMarginRowType($record->_rowType ?? null))
                    ->searchable(['orders.uid'])
                    ->sortable(['orders.uid']),

                PurchaseOrderNumberColumn::make('purchaseOrder.reference_number')
                    ->label('Inkooporder #')
                    ->linkOnly()
                    ->viewData(fn (Model $record): array => [
                        'allowLinkPurchaseOrder' => $this->marginRowShowsLinkPurchaseOrder($record),
                    ])
                    ->empty(fn (Model $record): bool => $this->isParentMarginRowType($record->_rowType ?? null)
                        || ($record instanceof PurchaseOrderInvoice && ! $record->isLinkedToActivePurchaseOrder()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        'purchase_orders.reference_number',
                        'like',
                        '%'.$search.'%',
                    ))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        'purchase_orders.reference_number',
                        strtoupper($direction) === 'DESC' ? 'desc' : 'asc',
                    )),

                PurchaseOrderInvoiceColumn::make('invoice_number')
                    ->label('Inkoopfactuur #')
                    ->empty(fn (Model $record): bool => $this->isParentMarginRowType($record->_rowType ?? null))
                    ->searchable(['purchase_order_invoices.invoice_number'])
                    ->sortable(['purchase_order_invoices.invoice_number']),

                PaidColumn::make('paid_at')
                    ->label('Betaald')
                    ->empty(fn (Model $record): bool => in_array($record->_rowType, [
                        PurchaseInvoiceRowType::OrderRow,
                        PurchaseInvoiceRowType::OrderRowParent,
                        PurchaseInvoiceRowType::OrderRowChild,
                        PurchaseInvoiceRowType::InvoiceRowParent,
                    ], true))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';

                        return $query->orderBy('orders.paid_at', $dir);
                    })
                    ->extraHeaderAttributes(['class' => 'paddingRight'])
                    ->extraCellAttributes(['class' => 'paddingRight']),

                TextColumn::make('days_since_received')
                    ->label('Ouderdom')
                    ->formatStateUsing(function ($state, PurchaseOrderInvoice|BaseOrder $record) {
                        if (! $this->marginRowShowsInvoiceAgeAndDueDate($record->_rowType ?? null)) {
                            return '';
                        }

                        if (! isset($record->days_since_received)) {
                            return '';
                        }

                        $daySuffix = $record->days_since_received === 1 ? ' dag' : ' dagen';

                        if ($record->is_late) {
                            return new HtmlString('<span class="purchaseOrderAgeNotice">' . $record->days_since_received . $daySuffix . '</span>');
                        }
                        return $record->days_since_received . $daySuffix;
                    })
                    ->extraHeaderAttributes(['class' => 'paddingRight'])
                    ->extraCellAttributes(['class' => 'paddingRight']),

                TextColumn::make('due_date')
                    ->label('Vervaldatum')
                    ->formatStateUsing(function ($state, PurchaseOrderInvoice|BaseOrder $record): string {
                        if (! $this->marginRowShowsInvoiceAgeAndDueDate($record->_rowType ?? null)) {
                            return '';
                        }

                        $dueDate = $record->due_date ?? null;

                        return $dueDate instanceof CarbonInterface
                            ? $dueDate->format('d-m-Y')
                            : '';
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

                        return $query->orderByRaw(
                            "purchase_order_invoices.due_date IS NULL, purchase_order_invoices.due_date {$dir}",
                        );
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw(
                        "DATE_FORMAT(purchase_order_invoices.due_date, '%d-%m-%Y') LIKE ?",
                        ['%' . $search . '%'],
                    )),

                TextColumn::make('companySalesPrice')
                    ->label('Verkoop')
                    ->formatStateUsing(fn ($state) => '€' . number_format(round($state, 2), 2, ',', '.'))
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                TextColumn::make('company_purchase_price_total')
                    ->label('Inkoop')
                    ->formatStateUsing(fn ($state) => '€' . number_format(round($state, 2), 2, ',', '.'))
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                TextColumn::make('admin_margin_summary')
                    ->label('Marge | Beheer'),

                TextColumn::make('total_amount')
                    ->label('Factuur | Inkoop')
                    ->formatStateUsing(fn ($state) => $state !== null ? '€' . number_format(round($state, 2), 2, ',', '.') : null)
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                TextColumn::make('price_cost_margin_summary')
                    ->label('Marge | Inkoop'),

                TextColumn::make('delta_margin')
                    ->label('Delta | Inkoop')
                    ->state(0) // needed to display this column since it's not in the model
                    ->formatStateUsing(function ($state, PurchaseOrderInvoice|BaseOrder $record) {
                        if ($record->total_amount === null) return null;

                        $companySalesPrice = $record->companySalesPrice ?? 0;
                        $company_purchase_price_total = $record->company_purchase_price_total ?? 0;
                        $paymentAmount = floatval($record->total_amount ?? 0);
                        $marginAdmin = $companySalesPrice - $company_purchase_price_total;
                        $marginPurchase = $companySalesPrice - $paymentAmount;

                        $delta = round($marginPurchase - $marginAdmin, 2);
                        $sign = $delta > 0 ? '+' : ($delta < 0 ? '-' : '');
                        return $sign . '€' . number_format(abs($delta), 2, ',', '.');
                    })
                    ->extraAttributes(function (PurchaseOrderInvoice|BaseOrder $record) {
                        $companySalesPrice = $record->companySalesPrice ?? 0;
                        $company_purchase_price_total = $record->company_purchase_price_total ?? 0;
                        $paymentAmount = floatval($record->total_amount ?? 0);
                        $marginAdmin = $companySalesPrice - $company_purchase_price_total;
                        $marginPurchase = $companySalesPrice - $paymentAmount;

                        return match (true) {
                            $marginPurchase < $marginAdmin => ['class' => 'purchaseOrderAgeNotice'],
                            $marginPurchase > $marginAdmin => ['class' => 'columnGreen'],
                            default => [],
                        };
                    })
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                OrderMarginsColumn::make('order_margins')
                    ->label('Marges')
                    ->empty(fn (Model $record): bool => ! $this->marginRowShowsOrderMargins($record->_rowType ?? null))
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                TextColumn::make('margin_supplier_name')
                    ->label('Leverancier')
                    ->state(fn (Model $record): string => $this->resolveMarginTableSupplierName($record))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('suppliers.name', $direction))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('suppliers.name', 'like', "%{$search}%"))
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),
            ])
            ->deferFilters(false)
            ->filters([], layout: FiltersLayout::AboveContent)
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->recordClasses(function (PurchaseOrderInvoice|BaseOrder $record) {
                return match ($record->_rowType) {
                    PurchaseInvoiceRowType::OrderRowParent,
                    PurchaseInvoiceRowType::InvoiceRowParent => 'tableParentRow',
                    PurchaseInvoiceRowType::OrderRowChild,
                    PurchaseInvoiceRowType::InvoiceRowChild => 'tableChildRow',
                    default => null,
                };
            })
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportMarginOverviewSpreadsheet()),
            ]);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function marginExportColumnDefinitions(): array
    {
        return [
            ['key' => 'request_main_uid', 'label' => 'Aanvraagnummer'],
            ['key' => 'order_uid', 'label' => 'Ordernummer'],
            ['key' => 'purchase_order_ref', 'label' => 'Inkooporder #'],
            ['key' => 'invoice_number', 'label' => 'Inkoopfactuur #'],
            ['key' => 'paid', 'label' => 'Betaald'],
            ['key' => 'days_since_received', 'label' => 'Ouderdom'],
            ['key' => 'due_date', 'label' => 'Vervaldatum'],
            ['key' => 'company_sales', 'label' => 'Verkoop'],
            ['key' => 'company_purchase', 'label' => 'Inkoop'],
            ['key' => 'admin_margin_summary', 'label' => 'Marge | Beheer'],
            ['key' => 'invoice_amount', 'label' => 'Factuur | Inkoop'],
            ['key' => 'price_cost_margin_summary', 'label' => 'Marge | Inkoop'],
            ['key' => 'delta_margin', 'label' => 'Delta | Inkoop'],
            ['key' => 'supplier', 'label' => 'Leverancier'],
        ];
    }

    private function marginExportResolveOrderForPaid(Model $record): ?BaseOrder
    {
        if ($record instanceof PurchaseOrderInvoice) {
            return $record->purchaseOrder?->order;
        }

        if ($record instanceof Order) {
            if (($record->_rowType ?? null) === PurchaseInvoiceRowType::OrderRowChild
                && $record->purchaseOrder instanceof PurchaseOrder) {
                return $record->purchaseOrder->order ?? $record;
            }

            return $record;
        }

        return null;
    }

    private function marginExportResolveOrderUid(Model $record): string
    {
        if ($record instanceof PurchaseOrderInvoice) {
            $order = $record->purchaseOrder?->order;

            return (string) ($order?->getUid() ?? $order?->uid ?? '');
        }

        if ($record instanceof Order) {
            if (($record->_rowType ?? null) === PurchaseInvoiceRowType::OrderRowChild
                && $record->purchaseOrder instanceof PurchaseOrder) {
                $linked = $record->purchaseOrder->order;

                return (string) ($linked?->getUid() ?? $linked?->uid ?? $record->getUid() ?? $record->uid ?? '');
            }

            return (string) ($record->getUid() ?? $record->uid ?? '');
        }

        return '';
    }

    /**
     * @return list<string|float|int>
     */
    private function marginRecordToExportRow(Model $record): array
    {
        $main = $this->resolveMarginTableMain($record);
        $rowType = $record->_rowType ?? null;
        $requestMainUid = $this->marginRowShowsRequestMainUid($rowType)
            ? (string) ($main?->getUid() ?? $main?->uid ?? '')
            : '';
        $orderUid = $this->isChildMarginRowType($rowType)
            ? ''
            : $this->marginExportResolveOrderUid($record);

        $poRef = '';
        $supplier = '';
        if (! $this->isParentMarginRowType($rowType)) {
            if ($record instanceof PurchaseOrderInvoice) {
                $po = $record->purchaseOrder;
                $poRef = $po !== null ? (string) $po->getReferenceNumber() : '';
                $supplier = (string) ($po?->supplier?->name ?? '');
            } elseif ($record instanceof Order && ($record->purchaseOrder ?? null) instanceof PurchaseOrder) {
                $po = $record->purchaseOrder;
                $poRef = (string) $po->getReferenceNumber();
                $supplier = (string) ($po->supplier?->name ?? '');
            }
        }

        $invoiceNumber = $record instanceof PurchaseOrderInvoice
            ? (string) ($record->invoice_number ?? '')
            : '';

        $paidOrder = $this->marginExportResolveOrderForPaid($record);
        $paidLabel = '';
        if ($paidOrder !== null) {
            $paidAt = $paidOrder->paid_at;
            if ($paidAt instanceof CarbonInterface) {
                $paidLabel = 'Ja, '.$paidAt->format('d/m/Y');
            } else {
                $paidLabel = 'Nee';
            }
        }

        $ageLabel = '';
        if (isset($record->days_since_received) && $record->days_since_received !== null) {
            $dayCount = (int) $record->days_since_received;
            $ageLabel = $dayCount.($dayCount === 1 ? ' dag' : ' dagen');
        }

        $dueDateFormatted = '';
        if ($record instanceof PurchaseOrderInvoice && $record->due_date instanceof CarbonInterface) {
            $dueDateFormatted = $record->due_date->format('d-m-Y');
        }

        $salesTotal = round((float) ($record->companySalesPrice ?? 0), 2);
        $purchaseTotal = round((float) ($record->company_purchase_price_total ?? 0), 2);
        $adminMarginSummary = (string) ($record->admin_margin_summary ?? '');

        $invoiceAmount = $record->total_amount;
        $invoiceAmountCell = $invoiceAmount === null ? '' : round((float) $invoiceAmount, 2);
        $invoiceMarginSummary = (string) ($record->price_cost_margin_summary ?? '');

        $delta = '';
        if ($record->total_amount !== null) {
            $sales = (float) ($record->companySalesPrice ?? 0);
            $purchase = (float) ($record->company_purchase_price_total ?? 0);
            $payment = (float) ($record->total_amount ?? 0);
            $delta = round(($sales - $payment) - ($sales - $purchase), 2);
        }

        return [
            $requestMainUid,
            $orderUid,
            $poRef,
            $invoiceNumber,
            $paidLabel,
            $ageLabel,
            $dueDateFormatted,
            $salesTotal,
            $purchaseTotal,
            $adminMarginSummary,
            $invoiceAmountCell,
            $invoiceMarginSummary,
            $delta,
            $supplier,
        ];
    }

    public function exportMarginOverviewSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'margin_orders_'.now()->format('Ymd_His').'_'.Str::random(6).'.xlsx';
        $filepath = storage_path('app/exports/'.$basename);

        $columns = $this->marginExportColumnDefinitions();

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 18;
        $xlsxOptions->setColumnWidthForRange(32, 10, 10);
        $xlsxOptions->setColumnWidthForRange(32, 12, 12);
        $xlsxOptions->setColumnWidthForRange(28, 14, 14);

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues(array_map(fn (array $c): string => $c['label'], $columns)));

        foreach ($this->getTableRecords() as $record) {
            $writer->addRow(Row::fromValues($this->marginRecordToExportRow($record)));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }

    public function getHeader(): ?View
    {
        return view('filament.components.back-to-overview-with-topbar-breadcrumbs', [
            'title' => 'Dashboard',
            'url' => route('filament.app.pages.dashboard'),
            'class' => 'quote-overview-back mt-4 mb-[-15px]',
            'breadcrumbs' => Filament::hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }
}

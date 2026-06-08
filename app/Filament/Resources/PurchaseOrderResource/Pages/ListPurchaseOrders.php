<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\ReleaseOrder;
use App\Services\InventoryService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $breadcrumb = 'Inkooporders';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 50;
    }

    public ?string $status = null;

    public ?PurchaseOrder $confirmPurchaseOrder = null;

    public ?ReleaseOrder $confirmReleaseOrder = null;

    /**
     * @return list<string>
     */
    public static function orderedProcessStatusValues(): array
    {
        return [
            PurchaseOrderStatus::Purchased->value,
        ];
    }

    public static function countOrderedCombined(): int
    {
        return self::countCombinedForStatuses(self::orderedProcessStatusValues());
    }

    /**
     * @param  list<string>  $statuses
     */
    public static function countCombinedForStatuses(array $statuses): int
    {
        return PurchaseOrderResource::getEloquentQuery()
            ->whereIn('purchase_orders.status', $statuses)
            ->count()
            + ReleaseOrderResource::getEloquentQuery()
            ->whereIn('release_orders.status', $statuses)
            ->count()
            + StockOrder::query()
            ->whereIn('orders.status', $statuses)
            ->count();
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $purchaseOrders
     * @param  Collection<int, ReleaseOrder>  $releaseOrders
     * @return Collection<int, array<string, mixed>>
     */
    protected function mergePurchaseOrdersAndReleaseOrdersToRows(
        Collection $purchaseOrders,
        Collection $releaseOrders,
        Collection $stockOrders,
        ?string $sortColumn,
        ?string $sortDirection,
    ): Collection {
        $recordKey = ArrayRecord::getKeyName();
        $rows = collect();
        foreach ($purchaseOrders as $po) {
            $id = 'po-' . $po->getId();
            $rows->push([
                $recordKey => $id,
                'key' => $id,
                'kind' => 'po',
                'purchaseOrder' => $po,
                'releaseOrder' => null,
                'sort_at' => $po->created_at,
                'status' => $po->getStatus()?->value,
            ]);
        }
        foreach ($releaseOrders as $ro) {
            $id = 'ro-' . $ro->getId();
            $rows->push([
                $recordKey => $id,
                'key' => $id,
                'kind' => 'ro',
                'purchaseOrder' => null,
                'releaseOrder' => $ro,
                'sort_at' => $ro->sent_at ?? $ro->created_at,
                'status' => $ro->getStatus()?->value,
            ]);
        }
        foreach ($stockOrders as $so) {
            if ($so->getStatus() === PurchaseOrderStatus::Initial) {
                continue;
            }

            $id = 'so-' . $so->getId();
            $rows->push([
                $recordKey => $id,
                'key' => $id,
                'kind' => 'so',
                'purchaseOrder' => null,
                'releaseOrder' => null,
                'stockOrder' => $so,
                'sort_at' => $so->sent_at ?? $so->created_at,
                'status' => $so->getStatus()?->value,
            ]);
        }

        $desc = $sortDirection !== 'asc';
        $rows = $rows->sortBy(
            function (array $row) use ($sortColumn): int|string {
                if ($sortColumn === 'supplier.name' || $sortColumn === 'counterparty') {
                    if ($row['kind'] === 'po') {
                        return (string) ($row['purchaseOrder']->supplier?->name ?? '');
                    }
                    if ($row['kind'] === 'so') {
                        return (string) ($row['stockOrder']->supplier?->name ?? '');
                    }

                    return (string) ($row['releaseOrder']->dealer?->getName() ?? '');
                }
                if ($sortColumn === 'type') {
                    if ($row['kind'] === 'po') {
                        return (string) $row['purchaseOrder']->getType()->value;
                    }
                    if ($row['kind'] === 'so') {
                        return 'stock';
                    }

                    return 'release';
                }

                return $row['sort_at']?->timestamp ?? 0;
            },
            SORT_REGULAR,
            $desc,
        );

        return $rows->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildIndexCombinedRecords(?string $sortColumn, ?string $sortDirection, ?string $search = null): Collection
    {
        $purchaseOrders = PurchaseOrderResource::getEloquentQuery()
            ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Draft->value)
            ->with(['supplier', 'order.main', 'main'])
            ->get();

        $releaseOrders = ReleaseOrderResource::getEloquentQuery()
            ->where('release_orders.status', '!=', ReleaseOrderStatus::Initial->value)
            ->with(['dealer', 'main'])
            ->get();
        $stockOrders = StockOrder::query()
            ->where('orders.status', '!=', PurchaseOrderStatus::Initial->value)
            ->where('orders.status', '!=', PurchaseOrderStatus::Draft->value)
            ->with(['supplier', 'main'])
            ->get();

        $rows = $this->mergePurchaseOrdersAndReleaseOrdersToRows(
            $purchaseOrders,
            $releaseOrders,
            $stockOrders,
            $sortColumn,
            $sortDirection,
        );

        $rows = $this->applyIndexFilters($rows);

        return $this->applyIndexSearch($rows, $search);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyIndexSearch(Collection $rows, ?string $search): Collection
    {
        $search = $search !== null ? Str::trim($search) : '';
        if ($search === '') {
            return $rows;
        }

        $words = array_filter(explode(' ', Str::lower($search)));

        return $rows->filter(function (array $row) use ($words): bool {
            $haystack = $this->getIndexRowSearchText($row);

            foreach ($words as $word) {
                if (! str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function getIndexRowSearchText(array $row): string
    {
        $parts = [];

        if ($row['kind'] === 'po') {
            $po = $row['purchaseOrder'];
            $parts[] = $po->reference_number;
            $parts[] = $po->supplier?->name;
            $parts[] = $po->getType()->getLabel();
            $parts[] = $po->getStatus()?->getLabel();
            $main = $po->main ?? $po->order?->main;
            $parts[] = $main?->uid;
            $parts[] = $main?->getSubtype()?->getLabel();
        } elseif ($row['kind'] === 'so') {
            $so = $row['stockOrder'];
            $parts[] = $so->getUidFormatted();
            $parts[] = $so->supplier?->name;
            $parts[] = 'Voorraad inkoop';
            $parts[] = $so->getStatus()?->getLabel();
            $parts[] = $so->main?->uid;
            $parts[] = $so->main?->getSubtype()?->getLabel();
        } else {
            $ro = $row['releaseOrder'];
            $parts[] = $ro->getReferenceNumber();
            $parts[] = $ro->dealer?->getName();
            $parts[] = 'Afroep';
            $parts[] = $ro->getStatus()?->getLabel();
            $parts[] = $ro->main?->uid;
            $parts[] = $ro->main?->getSubtype()?->getLabel();
        }

        return Str::lower(implode(' ', array_filter(array_map(
            fn (mixed $part): string => trim((string) $part),
            $parts,
        ), fn (string $part): bool => $part !== '')));
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyIndexFilters(Collection $rows): Collection
    {
        $dateState = $this->getTableFilterState('created_at') ?? [];
        $statusState = $this->getTableFilterState('status') ?? [];
        $typeState = $this->getTableFilterState('type') ?? [];
        $supplierState = $this->getTableFilterState('supplier_id') ?? [];

        $from = isset($dateState['created_from']) && filled($dateState['created_from']) ? Carbon::parse((string) $dateState['created_from'])->startOfDay() : null;
        $to = isset($dateState['created_to']) && filled($dateState['created_to']) ? Carbon::parse((string) $dateState['created_to'])->endOfDay() : null;
        $statuses = collect($statusState['status'] ?? [])->filter()->values()->all();
        $types = collect($typeState['type'] ?? [])->filter()->values()->all();
        $supplierIds = collect($supplierState['supplier_id'] ?? [])->filter()->map(fn($v): int => (int) $v)->values()->all();

        return $rows->filter(function (array $row) use ($from, $to, $statuses, $types, $supplierIds): bool {
            $sortAt = $row['sort_at'] ?? null;
            if ($from !== null && $sortAt instanceof Carbon && $sortAt->lt($from)) {
                return false;
            }
            if ($to !== null && $sortAt instanceof Carbon && $sortAt->gt($to)) {
                return false;
            }

            if ($from !== null && ! $sortAt instanceof Carbon) {
                return false;
            }
            if ($to !== null && ! $sortAt instanceof Carbon) {
                return false;
            }

            if ($statuses !== []) {
                $currentStatus = (string) ($row['status'] ?? '');
                if (! in_array($currentStatus, $statuses, true)) {
                    return false;
                }
            }

            if ($types !== []) {
                $currentType = $row['kind'] === 'ro'
                    ? 'release'
                    : (
                        $row['kind'] === 'so'
                        ? 'stock'
                        : (string) ($row['purchaseOrder']?->getType()?->value ?? '')
                    );

                if (! in_array($currentType, $types, true)) {
                    return false;
                }
            }

            if ($supplierIds !== []) {
                if (! in_array($row['kind'], ['po', 'so'], true)) {
                    return false;
                }

                $supplierId = $row['kind'] === 'so'
                    ? (int) ($row['stockOrder']?->supplier_id ?? 0)
                    : (int) ($row['purchaseOrder']?->supplier_id ?? 0);
                if (! in_array($supplierId, $supplierIds, true)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    protected function makeTable(): Table
    {
        $table = parent::makeTable();

        return $table
            ->recordAction(fn(Model|array $record, Table $table): ?string => null)
            ->recordUrl(fn(Model|array $record, Table $table): ?string => null);
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if (is_array($record) && isset($record['key'])) {
            return (string) $record['key'];
        }

        return parent::getTableRecordKey($record);
    }

    public function table(Table $table): Table
    {
        $poStatuses = PurchaseOrderStatus::visibleStatuses();
        $roStatuses = ReleaseOrderStatus::visibleStatuses();

        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection, ?string $search): Collection => $this->buildIndexCombinedRecords($sortColumn, $sortDirection, $search))
            ->searchable()
            ->searchPlaceholder('Zoeken')
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back breadcrumb-purchase-orders-list',
            ]))
            ->columns([
                TextColumn::make('sort_at')
                    ->label('Datum ingekocht')
                    ->sortable()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        $dt = $record['sort_at'];
                        if (! $dt instanceof Carbon) {
                            return new HtmlString('');
                        }

                        return new HtmlString('<div class="numberPlusDate noBorder">' . $dt->translatedFormat('d-m-Y') . '</div>');
                    }),

                TextColumn::make('document_ref')
                    ->label('Document #')
                    ->state(true)
                    ->html()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        if ($record['kind'] === 'po') {
                            $po = $record['purchaseOrder'];
                            if ($po->reference_number === null || $po->reference_number === '') {
                                return new HtmlString('<span class="text-gray-400 text-sm"><em>In behandeling...</em></span>');
                            }

                            return new HtmlString(
                                '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                    . route('filament.app.resources.purchase-orders.view', ['record' => $po->getId()])
                                    . '">' . e($po->reference_number) . '</a></div>'
                            );
                        }
                        if ($record['kind'] === 'so') {
                            $so = $record['stockOrder'];
                            $ref = $so->getUidFormatted();
                            if ($ref === null || $ref === '') {
                                return new HtmlString('<span class="text-gray-400 text-sm"><em>In behandeling...</em></span>');
                            }

                            return new HtmlString(
                                '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                    . route('filament.app.resources.stock-orders.view', ['record' => $so->getId()])
                                    . '">' . e($ref) . '</a></div>'
                            );
                        }
                        $ro = $record['releaseOrder'];

                        return new HtmlString(
                            '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                . ReleaseOrderResource::getUrl('view', ['record' => $ro->getId()])
                                . '">' . e($ro->getReferenceNumber()) . '</a></div>'
                        );
                    }),

                TextColumn::make('days_since_sent_at')
                    ->label('Ouderdom')
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): HtmlString|string {
                        $sentAt = $record['kind'] === 'po'
                            ? $record['purchaseOrder']->sent_at
                            : ($record['kind'] === 'so'
                                ? $record['stockOrder']->sent_at
                                : $record['releaseOrder']->sent_at);

                        if ($sentAt === null) {
                            return new HtmlString('<span class="text-gray-400 text-sm"><em>Nog niet verzonden</em></span>');
                        }

                        $days = max(
                            0,
                            (int) $sentAt->copy()->startOfDay()->diffInDays(now()->startOfDay())
                        );
                        $suffix = $days === 1 ? ' dag' : ' dagen';
                        $text = $days . $suffix;

                        $businessDaysSinceSent = now()->diffInDaysFiltered(
                            fn(Carbon $date): bool => ! $date->isToday() && $date->isWeekday(),
                            $sentAt,
                            true
                        );
                        $isLate = $businessDaysSinceSent >= PurchaseOrder::PURCHASE_ORDER_LATE_AFTER_BUSINESS_DAYS;

                        if ($isLate) {
                            return new HtmlString('<span class="purchaseOrderAgeNotice">' . e($text) . '</span>');
                        }

                        return $text;
                    }),

                TextColumn::make('type')
                    ->label('Type')
                    ->sortable()
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): string {
                        if ($record['kind'] === 'ro') {
                            return 'Afroep';
                        }
                        if ($record['kind'] === 'so') {
                            return 'Voorraad inkoop';
                        }

                        return $record['purchaseOrder']->getType()->getLabel();
                    }),

                SelectColumn::make('status')
                    ->options(function (array $record) use ($poStatuses, $roStatuses): array {
                        if ($record['kind'] === 'ro') {
                            return $roStatuses;
                        }

                        return $poStatuses;
                    })
                    ->selectablePlaceholder(false)
                    ->disabled()
                    ->disableOptionWhen(fn(string $value): bool => ! in_array($value, [
                        PurchaseOrderStatus::Confirmed->value,
                        ReleaseOrderStatus::Confirmed->value,
                    ], true))
                    ->updateStateUsing(function ($state, array $record): void {
                        if ($record['kind'] === 'po') {
                            $this->handleStatusChange($record['purchaseOrder'], (string) $state);
                        } elseif ($record['kind'] === 'ro') {
                            $this->handleReleaseOrderStatusChange($record['releaseOrder'], (string) $state);
                        }
                        $this->flushCachedTableRecords();
                        $this->dispatch('$refresh');
                    }),

                TextColumn::make('aanvraagnummer')
                    ->label('Aanvraagnummer')
                    ->state(true)
                    ->html()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        if ($record['kind'] === 'so') {
                            return new HtmlString('N.v.t.');
                        }

                        $main = null;
                        if ($record['kind'] === 'ro') {
                            $main = $record['releaseOrder']->main;
                        } else {
                            $po = $record['purchaseOrder'];
                            $main = $po->main ?? $po->order?->main;
                        }

                        if ($main === null || $main->uid === null || $main->uid === '') {
                            return new HtmlString('<span class="text-gray-400 text-sm"><em>In behandeling...</em></span>');
                        }

                        return new HtmlString(
                            '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                . route('filament.app.resources.mains.view', ['record' => $main->getId()])
                                . '">' . e($main->uid) . '</a></div>'
                        );
                    }),

                TextColumn::make('type_aanvraag')
                    ->label('Type aanvraag')
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): string {
                        $main = null;
                        if ($record['kind'] === 'ro') {
                            $main = $record['releaseOrder']->main;
                        } elseif ($record['kind'] === 'po') {
                            $po = $record['purchaseOrder'];
                            $main = $po->main ?? $po->order?->main;
                        }

                        return $main?->getSubtype()?->getLabel() ?? '-';
                    }),

                TextColumn::make('counterparty')
                    ->label('Leverancier / Dealer')
                    ->sortable()
                    ->state(true)
                    ->formatStateUsing(function ($state, array $record): string {
                        if ($record['kind'] === 'po') {
                            return (string) ($record['purchaseOrder']->supplier?->name ?? '');
                        }
                        if ($record['kind'] === 'so') {
                            return (string) ($record['stockOrder']->supplier?->name ?? '');
                        }

                        return (string) ($record['releaseOrder']->dealer?->getName() ?? '');
                    }),
            ])
            ->deferFilters(false)
            ->filters([
                PurchaseOrderResource::getDateFilter(),
                PurchaseOrderResource::getSupplierFilter(relationshipColumn: 'supplier'),
                PurchaseOrderResource::getPurchaseOrderStatusFilter(PurchaseOrderStatus::visibleStatuses() + ReleaseOrderStatus::visibleStatuses()),
                PurchaseOrderResource::getPurchaseOrderTypeFilter(PurchaseOrderType::labels()),
            ], layout: FiltersLayout::AboveContent);
    }

    /**
     * Index: alleen de standaard inkooporder-tabel (geen inkoopproces-tabs).
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Tabs voor subpagina's (inkoopproces); niet op /purchase-orders zelf.
     */
    protected function getPageTabs(): array
    {
        return [
            Action::make('btn1')
                ->url(route('filament.app.resources.purchase-orders.ordered'))
                ->extraAttributes(fn(): array => [
                    'class' => $this->status === 'ordered' ? 'tab-button-orange' : 'tab-button-white',
                ])
                ->label(fn(): string => 'Ingekocht/Niet bevestigd (' . self::countOrderedCombined() . ')'),

            Action::make('btn2')
                ->url(route('filament.app.resources.purchase-orders.partially-confirmed'))
                ->extraAttributes(fn(): array => [
                    'class' => $this->status === 'partially-confirmed' ? 'tab-button-orange' : 'tab-button-white',
                ])
                ->label(fn(): string => 'Gedeeltelijk bevestigd (' . self::countCombinedForStatuses([PurchaseOrderStatus::PartiallyConfirmed->value]) . ')'),

            Action::make('btn3')
                ->url(route('filament.app.resources.purchase-orders.confirmed'))
                ->extraAttributes(fn(): array => [
                    'class' => $this->status === 'confirmed' ? 'tab-button-orange' : 'tab-button-white',
                ])
                ->label(fn(): string => 'Bevestigd (' . self::countCombinedForStatuses([PurchaseOrderStatus::Confirmed->value]) . ')'),

            Action::make('btn4')
                ->url(route('filament.app.resources.purchase-orders.partially-delivered'))
                ->extraAttributes(fn(): array => [
                    'class' => $this->status === 'partially-delivered' ? 'tab-button-orange' : 'tab-button-white',
                ])
                ->label(fn(): string => 'Gedeeltelijk geleverd (' . self::countCombinedForStatuses([PurchaseOrderStatus::PartiallyDelivered->value]) . ')'),

            Action::make('btn5')
                ->url(route('filament.app.resources.purchase-orders.delivered'))
                ->extraAttributes(fn(): array => [
                    'class' => $this->status === 'delivered' ? 'tab-button-orange' : 'tab-button-white',
                ])
                ->label(fn(): string => 'Geleverd (' . self::countCombinedForStatuses([PurchaseOrderStatus::Delivered->value]) . ')'),

        ];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Inkoop',
            route('filament.app.resources.purchase-orders.index') => 'Inkooporders',
        ];
    }

    public function getSubpageBreadcrumbs(): array
    {
        return [
            '' => 'Inkoop',
            route('filament.app.resources.purchase-orders.confirmed') => 'Inkoopproces',
        ];
    }

    public function handleStatusChange(PurchaseOrder $record, PurchaseOrderStatus|string $state): void
    {
        if (empty($state) || empty($record)) {
            return;
        }

        $incoming = PurchaseOrderStatus::tryFrom((string) $state);
        if ($incoming === null) {
            return;
        }
        if (! $this->canMoveForwardPurchaseOrderStatus($record->getStatus(), $incoming)) {
            return;
        }

        if ($state === PurchaseOrderStatus::Delivered->value) {
            $record->loadMissing('orderProducts');
            if ($record->getType() !== PurchaseOrderType::Stock && $record->orderProductsAreAllPickedReceived()) {
                $record->setStatus(PurchaseOrderStatus::Delivered);
                $record->save();
                $this->flushCachedTableRecords();
                $this->dispatch('$refresh');

                return;
            }

            $record->setStatus(PurchaseOrderStatus::Delivered);
            $record->save();
            $this->confirmPurchaseOrder = $record;

            if ($record->getType() === PurchaseOrderType::Stock) {
                $this->dispatch('open-modal', id: 'mts_purchase_order_delivered_confirm');
            } else {
                $this->dispatch('open-modal', id: 'mto_purchase_order_delivered_confirm');
            }
        } else {
            $record->setStatus(PurchaseOrderStatus::tryFrom((string) $state));
            $record->save();

            if ($state === PurchaseOrderStatus::Confirmed->value) {
                foreach ($record->orderProducts as $orderProduct) {
                    $orderProduct->setStatus(OrderProductStatus::Confirmed);
                    $orderProduct->save();
                }
            }
        }
    }

    public function handleReleaseOrderStatusChange(ReleaseOrder $record, ReleaseOrderStatus|string $state): void
    {
        if ($state === '' || $state === null) {
            return;
        }

        $enum = $state instanceof ReleaseOrderStatus ? $state : ReleaseOrderStatus::tryFrom((string) $state);
        if ($enum === null) {
            return;
        }
        if (! $this->canMoveForwardReleaseOrderStatus($record->getStatus(), $enum)) {
            return;
        }

        if ($enum === ReleaseOrderStatus::Delivered) {
            $record->loadMissing('orderProducts');
            if ($record->orderProductsAreAllPickedReceived()) {
                $record->setStatus(ReleaseOrderStatus::Delivered);
                $record->save();

                return;
            }

            $record->setStatus(ReleaseOrderStatus::Delivered);
            $record->save();
            $this->confirmReleaseOrder = $record;
            $this->dispatch('open-modal', id: 'release_order_delivered_confirm');

            return;
        }

        $record->setStatus($enum);
        $record->save();
    }

    protected function canMoveForwardPurchaseOrderStatus(?PurchaseOrderStatus $from, PurchaseOrderStatus $to): bool
    {
        if ($from === null) {
            return true;
        }

        $rank = [
            PurchaseOrderStatus::Initial->value => 0,
            PurchaseOrderStatus::Draft->value => 5,
            PurchaseOrderStatus::Purchased->value => 10,
            PurchaseOrderStatus::PartiallyConfirmed->value => 20,
            PurchaseOrderStatus::Confirmed->value => 30,
            PurchaseOrderStatus::PartiallyDelivered->value => 40,
            PurchaseOrderStatus::Delivered->value => 50,
            PurchaseOrderStatus::Cancelled->value => 60,
        ];

        return ($rank[$to->value] ?? 0) >= ($rank[$from->value] ?? 0);
    }

    protected function canMoveForwardReleaseOrderStatus(?ReleaseOrderStatus $from, ReleaseOrderStatus $to): bool
    {
        if ($from === null) {
            return true;
        }

        $rank = [
            ReleaseOrderStatus::Initial->value => 0,
            ReleaseOrderStatus::Purchased->value => 10,
            ReleaseOrderStatus::PartiallyConfirmed->value => 20,
            ReleaseOrderStatus::Confirmed->value => 30,
            ReleaseOrderStatus::PartiallyDelivered->value => 40,
            ReleaseOrderStatus::Delivered->value => 50,
            ReleaseOrderStatus::Cancelled->value => 60,
        ];

        return ($rank[$to->value] ?? 0) >= ($rank[$from->value] ?? 0);
    }

    public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                ...$schema->getComponents(),
                view('livewire.modals.mts-purchase-order-delivered-confirm-modal'),
                view('livewire.modals.mto-purchase-order-delivered-confirm-modal'),
                view('livewire.modals.release-order-delivered-confirm-modal'),
            ]);
    }

    #[On('confirmMtsPurchaseOrderDelivered')]
    #[On('confirmMtoPurchaseOrderDelivered')]
    public function confirmPurchaseOrderDelivered(bool $confirm, ?string $type = null): void
    {
        $type = ($type === 'mts') ? 'mts' : 'mto';
        $purchaseOrder = $this->confirmPurchaseOrder;

        if ($confirm && $purchaseOrder !== null) {
            if ($type === 'mts') {
                foreach ($purchaseOrder->orderProducts as $orderProduct) {
                    if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToStock) {
                        $inventoryService = app(InventoryService::class);
                        $inventoryService->deliverOrderProduct($orderProduct);
                    }
                }

                $purchaseOrder->setStatus(PurchaseOrderStatus::Delivered);
                $purchaseOrder->save();

                Notification::make()
                    ->title('De inkooporderstatus is bijgewerkt naar Geleverd en de voorraad is opgeboekt.')
                    ->success()
                    ->send();
            } else {
                $purchaseOrder->applyMtoDeliveredModalConfirm(null);

                Notification::make()
                    ->title('Geleverde regels zijn op Gepickt (ingekocht) gezet.')
                    ->success()
                    ->send();
            }
        }

        $this->confirmPurchaseOrder = null;
        $id = $type === 'mts'
            ? 'mts_purchase_order_delivered_confirm'
            : 'mto_purchase_order_delivered_confirm';

        $this->dispatch('close-modal', id: $id);
        $this->flushCachedTableRecords();
        $this->dispatch('$refresh');
    }

    #[On('confirmReleaseOrderDelivered')]
    public function confirmReleaseOrderDelivered(bool $confirm): void
    {
        $releaseOrder = $this->confirmReleaseOrder;
        $modalId = 'release_order_delivered_confirm';

        if ($confirm && $releaseOrder !== null) {
            $releaseOrder->applyReleaseDeliveredModalConfirm(null);
            Notification::make()
                ->title('Geleverde regels zijn op Gepickt (ingekocht) gezet.')
                ->success()
                ->send();
        }

        $this->confirmReleaseOrder = null;
        $this->dispatch('close-modal', id: $modalId);
        $this->flushCachedTableRecords();
        $this->dispatch('$refresh');
    }
}

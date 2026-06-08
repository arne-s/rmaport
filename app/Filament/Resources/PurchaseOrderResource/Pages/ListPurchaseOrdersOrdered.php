<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\Concerns\HasPurchaseProcessPageBodyClass;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;


class ListPurchaseOrdersOrdered extends ListPurchaseOrders
{
    use HasPurchaseProcessPageBodyClass;

    protected static string $resource = PurchaseOrderResource::class;

    public ?string $status = 'ordered';

    /**
     * ListRecords::makeTable() type-hints Model for recordAction/recordUrl; combined PO+RO rows are arrays.
     */
    protected function makeTable(): Table
    {
        $table = parent::makeTable();

        return $table
            ->recordAction(fn (Model|array $record, Table $table): ?string => null)
            ->recordUrl(fn (Model|array $record, Table $table): ?string => null);
    }

    public function getBreadcrumbs(): array
    {
        return array_merge(parent::getSubpageBreadcrumbs(), ['Ingekocht/Afgeroepen']);
    }

    protected function getHeaderActions(): array
    {
        return parent::getPageTabs();
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if (is_array($record) && isset($record['key'])) {
            return (string) $record['key'];
        }

        return parent::getTableRecordKey($record);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildOrderedCombinedRecords(?string $sortColumn, ?string $sortDirection): Collection
    {
        $statuses = self::orderedProcessStatusValues();

        $purchaseOrders = PurchaseOrderResource::getEloquentQuery()
            ->whereIn('purchase_orders.status', $statuses)
            ->with(['supplier', 'order.main', 'main'])
            ->get();

        $releaseOrders = ReleaseOrderResource::getEloquentQuery()
            ->whereIn('release_orders.status', $statuses)
            ->with(['dealer', 'main'])
            ->get();
        $stockOrders = StockOrder::query()
            ->whereIn('orders.status', $statuses)
            ->with(['supplier', 'main'])
            ->get();

        return $this->mergePurchaseOrdersAndReleaseOrdersToRows(
            $purchaseOrders,
            $releaseOrders,
            $stockOrders,
            $sortColumn,
            $sortDirection,
        );
    }

    public function table(Table $table): Table
    {
        $poStatuses = PurchaseOrderStatus::visibleStatuses();
        $roStatuses = ReleaseOrderStatus::visibleStatuses();

        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection): Collection => $this->buildOrderedCombinedRecords($sortColumn, $sortDirection))
            ->columns([
                TextColumn::make('sort_at')
                    ->label('Datum ingekocht')
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(function ($state, array $record): HtmlString {
                        $dateValue = $state ?? $record['sort_at'] ?? null;
                        if ($dateValue === null) {
                            return new HtmlString('<span class="text-gray-400 text-sm"><em>In behandeling...</em></span>');
                        }

                        return new HtmlString(e(\Illuminate\Support\Carbon::parse((string) $dateValue)->format('d-m-Y')));
                    })
                    ->disabledClick(),

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

                            return new HtmlString(
                                '<div class="linksDocuments"><a class="openDocument main-request-number-link" href="'
                                . route('filament.app.resources.stock-orders.view', ['record' => $so->getId()])
                                . '">' . e($ref ?: 'In behandeling...') . '</a></div>'
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
                            fn (Carbon $date): bool => ! $date->isToday() && $date->isWeekday(),
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
                    ->disableOptionWhen(fn (string $value): bool => ! in_array($value, [
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

            ]);
    }

     public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                        'class' => 'breadcrumb-mob-purchase-order',
                    ]),
                ...$schema->getComponents(),
                view('livewire.modals.mts-purchase-order-delivered-confirm-modal'),
                view('livewire.modals.mto-purchase-order-delivered-confirm-modal'),
                view('livewire.modals.release-order-delivered-confirm-modal'),
            ]);
    }
}

<?php

namespace App\Filament\Resources\PurchaseOrderInvoiceResource\Pages;

use App\Enums\PurchaseOrderType;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\PurchaseOrderInvoiceResource;
use App\Filament\Resources\PurchaseOrderInvoiceResource\Actions\LinkPurchaseOrderAction;
use App\Filament\Tables\Columns\OrderNumberPageColumn;
use App\Filament\Tables\Columns\PaidColumn;
use App\Filament\Tables\Columns\PurchaseOrderInvoiceColumn;
use App\Filament\Tables\Columns\PurchaseOrderNumberColumn;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListPurchaseOrderInvoices extends ListRecords
{
    protected static string $resource = PurchaseOrderInvoiceResource::class;

    protected static ?string $breadcrumb = 'Inkoopfacturen';

    public ?int $linkingPurchaseOrderInvoiceId = null;

    protected function isTablePaginationEnabled(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [];
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

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->orderByRaw('COALESCE(entry_date, created_at) DESC');
    }

    public function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Datum')
                    ->date('j M Y')
                    ->sortable(['entry_date'])
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw("DATE_FORMAT(entry_date, '%d-%m-%Y') LIKE ?", ['%' . $search . '%'])
                        ->orWhereRaw("DATE_FORMAT(entry_date, '%e %b. %Y') LIKE ?", ['%' . $search . '%'])),

                PurchaseOrderInvoiceColumn::make('invoice_number')
                    ->label('Inkoopfactuur')
                    ->viewData(['displayDate' => false])
                    ->searchable(['invoice_number'])
                    ->sortable(['invoice_number']),

                TextColumn::make('total_amount_inc_vat')
                    ->label('Factuurbedrag')
                    ->money('eur')
                    ->sortable(['total_amount_inc_vat']),

                TextColumn::make('description')
                    ->label('Omschrijving')
                    ->limit(30)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        if (! is_string($state) || strlen($state) <= 30) {
                            return null;
                        }

                        return $state;
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('purchase_order_reference')
                    ->label('Referentie')
                    ->state(fn (PurchaseOrderInvoice $record): ?string => $record->activePurchaseOrder()?->reference_number)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHasMorph(
                        'orderable',
                        [PurchaseOrder::class],
                        fn (Builder $purchaseOrderQuery): Builder => $purchaseOrderQuery->where('reference_number', 'like', '%' . $search . '%'),
                    ))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->leftJoin('purchase_orders', function ($join): void {
                            $join->on('purchase_orders.id', '=', 'purchase_order_invoices.orderable_id')
                                ->where('purchase_order_invoices.orderable_type', '=', PurchaseOrder::class);
                        })
                        ->orderBy('purchase_orders.reference_number', $direction)
                        ->select('purchase_order_invoices.*')),

                OrderNumberPageColumn::make('orderable.order.uid')
                    ->label('Aanvraagnummer')
                    ->linkOnly()
                    ->empty(fn (PurchaseOrderInvoice $record): bool => ! $record->isLinkedToActivePurchaseOrder() || $record->activePurchaseOrder()?->getType() === PurchaseOrderType::Stock)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->whereHasMorph(
                            'orderable',
                            [PurchaseOrder::class],
                            fn (Builder $purchaseOrderQuery): Builder => $purchaseOrderQuery->whereHas(
                                'order',
                                fn (Builder $orderQuery): Builder => $orderQuery->where('uid', 'like', '%' . $search . '%'),
                            )->orWhereHas(
                                'main',
                                fn (Builder $mainQuery): Builder => $mainQuery->where('uid', 'like', '%' . $search . '%'),
                            ),
                        );
                    })),

                TextColumn::make('due_date')
                    ->label('Vervaldatum')
                    ->date('d-m-Y')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("due_date IS NULL, due_date {$direction}"))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw("DATE_FORMAT(due_date, '%d-%m-%Y') LIKE ?", ['%' . $search . '%'])),

                TextColumn::make('days_since_received')
                    ->label('Ouderdom')
                    ->formatStateUsing(function ($state, PurchaseOrderInvoice $record): HtmlString|string {
                        if ($record->days_since_received === null) {
                            return '';
                        }

                        $dagen = $record->days_since_received === 1 ? ' dag' : ' dagen';

                        if ($record->is_late) {
                            return new HtmlString('<span class="purchaseOrderAgeNotice">' . $record->days_since_received . $dagen . '</span>');
                        }

                        return $record->days_since_received . $dagen;
                    }),

                PaidColumn::make('paid_at')
                    ->label('Betaald')
                    ->sortable(['paid_at']),

                TextColumn::make('supplier_name')
                    ->label('Leverancier')
                    ->formatStateUsing(fn (?string $state, PurchaseOrderInvoice $record): ?string => $record->activePurchaseOrder()?->supplier?->name ?? $state)
                    ->limit(20)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();

                        if (! is_string($state) || strlen($state) <= 20) {
                            return null;
                        }

                        return $state;
                    })
                    ->sortable()
                    ->searchable(),

                PurchaseOrderNumberColumn::make('orderable.reference_number')
                    ->label('Document #')
                    ->linkOnly()
                    ->viewData(['allowLinkPurchaseOrder' => true])
                    ->empty(fn (PurchaseOrderInvoice $record): bool => ! $record->isLinkedToActivePurchaseOrder())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHasMorph(
                        'orderable',
                        [PurchaseOrder::class],
                        fn (Builder $purchaseOrderQuery): Builder => $purchaseOrderQuery->where('reference_number', 'like', '%' . $search . '%'),
                    ))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->leftJoin('purchase_orders', function ($join): void {
                            $join->on('purchase_orders.id', '=', 'purchase_order_invoices.orderable_id')
                                ->where('purchase_order_invoices.orderable_type', '=', PurchaseOrder::class);
                        })
                        ->orderBy('purchase_orders.reference_number', $direction)
                        ->select('purchase_order_invoices.*')),

                IconColumn::make('exact_id')
                    ->label('Exact')
                    ->icons([
                        'heroicon-o-check-circle' => fn (PurchaseOrderInvoice $record): bool => $record->isExactSynced(),
                        'heroicon-o-clock' => fn (PurchaseOrderInvoice $record): bool => $record->isPendingExactSync(),
                        'heroicon-o-x-circle' => fn (PurchaseOrderInvoice $record): bool => $record->hasExactSyncError(),
                    ])
                    ->colors([
                        'success' => fn (PurchaseOrderInvoice $record): bool => $record->isExactSynced(),
                        'warning' => fn (PurchaseOrderInvoice $record): bool => $record->isPendingExactSync(),
                        'danger' => fn (PurchaseOrderInvoice $record): bool => $record->hasExactSyncError(),
                    ])
                    ->tooltip(fn (PurchaseOrderInvoice $record): ?string => $record->exactSyncStatusLabel()),
            ])
            ->deferFilters(false)
            ->filters([], layout: FiltersLayout::AboveContent)
            ->recordActions([]);
    }
}

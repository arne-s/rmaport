<?php

namespace App\Filament\Resources\PurchaseOrderConfirmationResource\Pages;

use App\Enums\PurchaseOrderType;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\PurchaseOrderConfirmationResource;
use App\Filament\Tables\Columns\OrderNumberPageColumn;
use App\Filament\Tables\Columns\PurchaseOrderConfirmationDocumentColumn;
use App\Filament\Tables\Columns\PurchaseOrderNumberColumn;
use App\Models\PurchaseOrderConfirmation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListPurchaseOrderConfirmations extends ListRecords
{
    protected static string $resource = PurchaseOrderConfirmationResource::class;

    protected static ?string $title = 'Inkoopbevestigingen';

    protected static ?string $breadcrumb = 'Inkoopbevestigingen';

    protected function isTablePaginationEnabled(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->orderByRaw('COALESCE(email_received_at, created_at) DESC');
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
                TextColumn::make('received_at')
                    ->label('Datum')
                    ->state(fn (PurchaseOrderConfirmation $record): ?\Illuminate\Support\Carbon => $record->email_received_at ?? $record->created_at)
                    ->date('j M Y')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('COALESCE(email_received_at, created_at) ' . ($direction === 'asc' ? 'asc' : 'desc'))),

                PurchaseOrderConfirmationDocumentColumn::make('pdf_path')
                    ->label('Inkoopbevestiging')
                    ->viewData(['displayDate' => false]),

                OrderNumberPageColumn::make('purchaseOrder.order.uid')
                    ->label('Aanvraagnummer')
                    ->linkOnly()
                    ->empty(fn (PurchaseOrderConfirmation $record): bool => $record->purchaseOrder?->getType() === PurchaseOrderType::Stock)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query->whereHas(
                            'purchaseOrder.order',
                            fn (Builder $orderQuery): Builder => $orderQuery->where('uid', 'like', '%' . $search . '%'),
                        )->orWhereHas(
                            'purchaseOrder.main',
                            fn (Builder $mainQuery): Builder => $mainQuery->where('uid', 'like', '%' . $search . '%'),
                        );
                    })),

                TextColumn::make('expected_delivery_date')
                    ->label('Verwachte leverdatum')
                    ->date('d-m-Y')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("expected_delivery_date IS NULL, expected_delivery_date {$direction}")),

                TextColumn::make('days_since_received')
                    ->label('Ouderdom')
                    ->formatStateUsing(function ($state, PurchaseOrderConfirmation $record): HtmlString|string {
                        if ($record->days_since_received === null) {
                            return '';
                        }

                        $dagen = $record->days_since_received === 1 ? ' dag' : ' dagen';

                        return $record->days_since_received . $dagen;
                    }),

                TextColumn::make('purchaseOrder.supplier.name')
                    ->label('Leverancier')
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

                PurchaseOrderNumberColumn::make('purchaseOrder.reference_number')
                    ->label('Document #')
                    ->linkOnly()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'purchaseOrder',
                        fn (Builder $purchaseOrderQuery): Builder => $purchaseOrderQuery->where('reference_number', 'like', '%' . $search . '%'),
                    ))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_confirmations.purchase_order_id')
                        ->orderBy('purchase_orders.reference_number', $direction)
                        ->select('purchase_order_confirmations.*')),
            ])
            ->deferFilters(false)
            ->filters([], layout: FiltersLayout::AboveContent)
            ->recordActions([]);
    }
}

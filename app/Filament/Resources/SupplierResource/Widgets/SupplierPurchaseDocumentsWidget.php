<?php

namespace App\Filament\Resources\SupplierResource\Widgets;

use App\Enums\OrderType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPurchaseDocumentTableRow;
use App\Support\NavigationLink;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierPurchaseDocumentsWidget extends TableWidget
{
    protected static ?string $heading = '';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getTableQuery(): Builder
    {
        if (! $this->record instanceof Supplier) {
            return SupplierPurchaseDocumentTableRow::query()->fromSub(
                PurchaseOrder::query()->whereRaw('0 = 1')->selectRaw(
                    "CONCAT('purchase-', id) as id, 'purchase' as source_type, id as source_id, reference_number as document_number, type as purchase_order_type, sent_at, status, created_at"
                ),
                'supplier_purchase_documents'
            );
        }

        $supplierId = $this->record->getId();

        $purchaseOrders = PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->where('status', '!=', PurchaseOrderStatus::Draft->value)
            ->selectRaw(
                "CONCAT('purchase-', id) as id, 'purchase' as source_type, id as source_id, reference_number as document_number, type as purchase_order_type, sent_at, status, created_at"
            );

        // StockOrder global scope already constrains `type`; avoid placeholders in UNION
        // SELECT — MariaDB/MySQL can mis-merge bindings on unioned queries.
        $orderTypeLiteral = OrderType::Order->value;
        $stockPurchaseTypeLiteral = PurchaseOrderType::Stock->value;

        $stockOrders = StockOrder::query()
            ->where('supplier_id', $supplierId)
            ->where('status', '!=', PurchaseOrderStatus::Draft->value)
            ->selectRaw(
                "CONCAT('stock-', id) as id, 'stock' as source_type, id as source_id, CONCAT(uid, IF(type != '{$orderTypeLiteral}' AND rev > 1, CONCAT('/', rev), '')) as document_number, '{$stockPurchaseTypeLiteral}' as purchase_order_type, sent_at, status, created_at"
            );

        return SupplierPurchaseDocumentTableRow::query()->fromSub(
            $purchaseOrders->unionAll($stockOrders),
            'supplier_purchase_documents'
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->recordUrl(null)
            ->columns([

                TextColumn::make('Type document')
                    ->label('Type document')
                    ->state(fn (SupplierPurchaseDocumentTableRow $record): string => $record->source_type === 'purchase' ? 'Inkooporder' : 'Voorraadorder'),

                TextColumn::make('purchase_order_type')
                    ->label('Type inkooporder')
                    ->formatStateUsing(fn (PurchaseOrderType $state): string => $state->getLabel() ?? '-'),

                TextColumn::make('document_number')
                    ->label('Documentnummer')
                    ->formatStateUsing(fn (SupplierPurchaseDocumentTableRow $record) => NavigationLink::render(
                        $this->getDocumentUrl($record),
                        $record->document_number,
                    ))
                    ->openUrlInNewTab()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('document_number', 'like', "%{$search}%");
                    }),

                TextColumn::make('document_date')
                    ->label('Datum')
                    ->state(function (SupplierPurchaseDocumentTableRow $record): string {
                        return $record->sent_at?->format('Y-m-d') ?? '-';
                    }),


                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Geen inkoopdocumenten');
    }

    private function getDocumentUrl(SupplierPurchaseDocumentTableRow $record): ?string
    {
        if ($record->source_type === 'purchase') {
            return route('filament.app.resources.purchase-orders.view', ['record' => $record->source_id]);
        }

        if ($record->source_type === 'stock') {
            return route('filament.app.resources.stock-orders.view', ['record' => $record->source_id]);
        }

        return null;
    }
}

<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Enums\OrderProductStatus;
use App\Enums\OrderType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Support\PurchaseAuthorization;
use App\Models\Order\BaseOrder;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReleaseOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProductPurchaseDocumentsWidget extends TableWidget
{
    protected static ?string $heading = '';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public static function canView(): bool
    {
        return PurchaseAuthorization::canManage();
    }

    protected function getTableQuery(): Builder
    {
        if (! $this->record instanceof Product) {
            return OrderProduct::query()->whereRaw('1 = 0');
        }

        return OrderProduct::query()
            ->where('product_id', $this->record->getId())
            ->where('status', '!=', OrderProductStatus::Initial->value)
            ->whereDoesntHave('order', function (Builder $orderQuery): void {
                $orderQuery
                    ->where('type', OrderType::StockOrder->value)
                    ->whereIn('status', [
                        PurchaseOrderStatus::Initial->value,
                        PurchaseOrderStatus::Draft->value,
                    ]);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('purchaseOrder', function (Builder $purchaseOrderQuery): void {
                        $purchaseOrderQuery
                            ->whereNotIn('status', [
                                PurchaseOrderStatus::Initial->value,
                                PurchaseOrderStatus::Draft->value,
                            ])
                            ->whereNotNull('reference_number')
                            ->where('reference_number', '!=', '')
                            ->where('reference_number', '!=', 'concept');
                    })
                    ->orWhereHas('releaseOrder', function (Builder $releaseOrderQuery): void {
                        $releaseOrderQuery
                            ->where('status', '!=', ReleaseOrderStatus::Initial->value)
                            ->whereNotNull('reference_number')
                            ->where('reference_number', '!=', '')
                            ->where('reference_number', '!=', 'concept');
                    })
                    ->orWhereHas('order', function (Builder $orderQuery): void {
                        $orderQuery
                            ->where('type', OrderType::StockOrder->value)
                            ->whereNotIn('status', [
                                PurchaseOrderStatus::Initial->value,
                                PurchaseOrderStatus::Draft->value,
                            ])
                            ->whereNotNull('uid')
                            ->where('uid', '!=', '');
                    });
            })
            ->with([
                'purchaseOrder:id,reference_number,sent_at,status',
                'releaseOrder:id,reference_number,sent_at,status',
                'order:id,uid,rev,type,sent_at,status',
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->recordUrl(null)
            ->columns([
                TextColumn::make('document_number')
                    ->label('Inkoopordernummer')
                    ->state(fn (OrderProduct $record): string => $this->getDocumentNumber($record))
                    ->url(fn (OrderProduct $record): ?string => $this->getDocumentUrl($record))
                    ->openUrlInNewTab()
                    ->extraCellAttributes(['class' => 'fi-ta-cell--nav-link'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $documentQuery) use ($search): void {
                            $documentQuery
                                ->whereHas('purchaseOrder', fn (Builder $purchaseOrderQuery) => $purchaseOrderQuery->where('reference_number', 'like', "%{$search}%"))
                                ->orWhereHas('releaseOrder', fn (Builder $releaseOrderQuery) => $releaseOrderQuery->where('reference_number', 'like', "%{$search}%"))
                                ->orWhereHas('order', fn (Builder $stockOrderQuery) => $stockOrderQuery
                                    ->where('type', OrderType::StockOrder->value)
                                    ->where('uid', 'like', "%{$search}%"));
                        });
                    }),

                TextColumn::make('document_date')
                    ->label('Datum ingekocht')
                    ->state(function (OrderProduct $record): string {
                        $sentAt = $this->getDocumentSentAt($record);

                        return $sentAt?->format('Y-m-d') ?? '-';
                    }),

                TextColumn::make('ordered_qty')
                    ->label('Aantal besteld')
                    ->state(function (OrderProduct $record): string {
                        $orderedQty = $this->getOrderedQtySum($record);

                        if ($orderedQty === null) {
                            return '-';
                        }

                        if (fmod($orderedQty, 1.0) === 0.0) {
                            return (string) (int) $orderedQty;
                        }

                        return number_format($orderedQty, 2, ',', '.');
                    }),

                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-'),

                TextColumn::make('stock_booked_at')
                    ->label('Voorraad opgeboekt')
                    ->state(function (OrderProduct $record): string {
                        $averageDeliveredAt = $this->getAverageDeliveredAt($record);

                        return $averageDeliveredAt?->format('Y-m-d') ?? '-';
                    }),

                TextColumn::make('lead_time')
                    ->label('Levertijd')
                    ->state(function (OrderProduct $record): string {
                        $averageLeadTimeSeconds = $this->getAverageLeadTimeSeconds($record);

                        return $this->formatDuration($averageLeadTimeSeconds);
                    }),

                TextColumn::make('expected_delivery_date')
                    ->label('Verwachte leverdatum')
                    ->state(function (OrderProduct $record): string {
                        $expectedDeliveryDate = $this->getExpectedDeliveryDate($record);

                        return $expectedDeliveryDate?->format('Y-m-d') ?? '-';
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Geen inkoopdocumenten');
    }

    private function getDocumentNumber(OrderProduct $record): string
    {
        if ($record->purchase_order_id !== null) {
            $purchaseOrder = $record->relationLoaded('purchaseOrder')
                ? $record->purchaseOrder
                : PurchaseOrder::query()->find($record->purchase_order_id);

            if ($this->isDisplayablePurchaseOrder($purchaseOrder)) {
                return $purchaseOrder->getReferenceNumber();
            }
        }

        if ($record->release_order_id !== null) {
            $releaseOrder = $record->relationLoaded('releaseOrder')
                ? $record->releaseOrder
                : ReleaseOrder::query()->find($record->release_order_id);

            if ($this->isDisplayableReleaseOrder($releaseOrder)) {
                return $releaseOrder->getReferenceNumber();
            }
        }

        if ($record->order_id !== null) {
            $order = $record->relationLoaded('order')
                ? $record->order
                : $record->order()->first();

            if ($order !== null
                && $order->getType() === OrderType::StockOrder
                && $this->isDisplayableStockOrder($order)) {
                return $order->getUidFormatted();
            }
        }

        return '-';
    }

    private function isDisplayablePurchaseOrder(?PurchaseOrder $purchaseOrder): bool
    {
        if ($purchaseOrder === null) {
            return false;
        }

        $status = $purchaseOrder->getStatus();
        $referenceNumber = trim((string) $purchaseOrder->getReferenceNumber());

        return ! in_array($status, [PurchaseOrderStatus::Initial, PurchaseOrderStatus::Draft], true)
            && $referenceNumber !== ''
            && $referenceNumber !== 'concept';
    }

    private function isDisplayableReleaseOrder(?ReleaseOrder $releaseOrder): bool
    {
        if ($releaseOrder === null) {
            return false;
        }

        $referenceNumber = trim((string) $releaseOrder->getReferenceNumber());

        return $releaseOrder->getStatus() !== ReleaseOrderStatus::Initial
            && $referenceNumber !== ''
            && $referenceNumber !== 'concept';
    }

    private function isDisplayableStockOrder(BaseOrder $order): bool
    {
        $status = $order->getStatus();
        $statusString = $status instanceof \BackedEnum ? $status->value : (string) $status;
        $uid = trim((string) ($order->getUid() ?? ''));

        return ! in_array($statusString, [
            PurchaseOrderStatus::Initial->value,
            PurchaseOrderStatus::Draft->value,
        ], true)
            && $uid !== '';
    }

    private function getDocumentUrl(OrderProduct $record): ?string
    {
        if ($record->purchase_order_id !== null) {
            $purchaseOrder = $record->relationLoaded('purchaseOrder')
                ? $record->purchaseOrder
                : PurchaseOrder::query()->find($record->purchase_order_id);

            if ($this->isDisplayablePurchaseOrder($purchaseOrder)) {
                return route('filament.app.resources.purchase-orders.view', ['record' => $record->purchase_order_id]);
            }
        }

        if ($record->release_order_id !== null) {
            $releaseOrder = $record->relationLoaded('releaseOrder')
                ? $record->releaseOrder
                : ReleaseOrder::query()->find($record->release_order_id);

            if ($this->isDisplayableReleaseOrder($releaseOrder)) {
                return route('filament.app.resources.release-orders.view', ['record' => $record->release_order_id]);
            }
        }

        if ($record->order_id !== null) {
            $order = $record->relationLoaded('order')
                ? $record->order
                : $record->order()->first();

            if ($order !== null
                && $order->getType() === OrderType::StockOrder
                && $this->isDisplayableStockOrder($order)) {
                return route('filament.app.resources.stock-orders.view', ['record' => $record->order_id]);
            }
        }

        return null;
    }

    private function getDocumentSentAt(OrderProduct $record): ?Carbon
    {
        if ($record->purchaseOrder !== null) {
            return $record->purchaseOrder->getSentAt();
        }

        if ($record->releaseOrder !== null) {
            return $record->releaseOrder->getSentAt();
        }

        if ($record->order !== null && $record->order->getType() === OrderType::StockOrder) {
            return $record->order->getSentAt();
        }

        return null;
    }

    private function getAverageDeliveredAt(OrderProduct $record): ?Carbon
    {
        $query = $this->getRelatedDocumentOrderProductsQuery($record)
            ->whereNotNull('delivered_at');

        $averageTimestamp = (float) $query
            ->selectRaw('AVG(UNIX_TIMESTAMP(delivered_at)) as avg_ts')
            ->value('avg_ts');

        if ($averageTimestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) round($averageTimestamp));
    }

    private function getAverageLeadTimeSeconds(OrderProduct $record): ?float
    {
        $sentAt = $this->getDocumentSentAt($record);
        if ($sentAt === null) {
            return null;
        }

        $averageLeadTimeSeconds = (float) $this->getRelatedDocumentOrderProductsQuery($record)
            ->whereNotNull('delivered_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, ?, delivered_at)) as avg_seconds', [$sentAt->toDateTimeString()])
            ->value('avg_seconds');

        return $averageLeadTimeSeconds > 0 ? $averageLeadTimeSeconds : null;
    }

    private function getOrderedQtySum(OrderProduct $record): ?float
    {
        $orderedQty = (float) $this->getRelatedDocumentOrderProductsQuery($record)
            ->selectRaw('SUM(qty) as ordered_qty')
            ->value('ordered_qty');

        return $orderedQty > 0 ? $orderedQty : null;
    }

    private function getExpectedDeliveryDate(OrderProduct $record): ?Carbon
    {
        $sentAt = $this->getDocumentSentAt($record);
        $averageLeadTimeSeconds = $this->getAverageLeadTimeSeconds($record);

        if ($sentAt === null || $averageLeadTimeSeconds === null || $averageLeadTimeSeconds <= 0) {
            return null;
        }

        return $sentAt->copy()->addSeconds((int) round($averageLeadTimeSeconds));
    }

    private function getRelatedDocumentOrderProductsQuery(OrderProduct $record): Builder
    {
        $query = OrderProduct::query()->where('product_id', $record->product_id);

        if ($record->purchase_order_id !== null) {
            return $query->where('purchase_order_id', $record->purchase_order_id);
        }

        if ($record->release_order_id !== null) {
            return $query->where('release_order_id', $record->release_order_id);
        }

        return $query->where('order_id', $record->order_id);
    }

    private function formatDuration(?float $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '-';
        }

        $totalSeconds = (int) round($seconds);
        $days = intdiv($totalSeconds, 86400);
        $hours = intdiv($totalSeconds % 86400, 3600);

        if ($days > 0) {
            $dayLabel = $days === 1 ? 'dag' : 'dagen';

            if ($hours > 0) {
                $hourLabel = $hours === 1 ? 'uur' : 'uur';

                return "{$days} {$dayLabel}, {$hours} {$hourLabel}";
            }

            return "{$days} {$dayLabel}";
        }

        $minutes = intdiv($totalSeconds % 3600, 60);
        if ($hours > 0) {
            $hourLabel = $hours === 1 ? 'uur' : 'uur';
            if ($minutes > 0) {
                $minuteLabel = $minutes === 1 ? 'minuut' : 'minuten';

                return "{$hours} {$hourLabel}, {$minutes} {$minuteLabel}";
            }

            return "{$hours} {$hourLabel}";
        }

        if ($minutes > 0) {
            $minuteLabel = $minutes === 1 ? 'minuut' : 'minuten';

            return "{$minutes} {$minuteLabel}";
        }

        return '<1 minuut';
    }
}


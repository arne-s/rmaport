<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages\Concerns;

use App\Enums\PurchaseOrderStatus;
use App\Enums\ReleaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\ReleaseOrders\ReleaseOrderResource;
use App\Models\Order\StockOrder;
use Carbon\Carbon;
use Filament\Support\ArrayRecord;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait CombinedPurchaseOrderListRecords
{
    /**
     * @return list<string>
     */
    abstract protected function combinedListStatusValues(): array;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function buildCombinedListRecords(?string $sortColumn, ?string $sortDirection): Collection
    {
        $statuses = $this->combinedListStatusValues();

        $purchaseOrders = PurchaseOrderResource::getEloquentQuery()
            ->whereIn('purchase_orders.status', $statuses)
            ->with([
                'supplier',
                'order.main',
                'main',
                'confirmations' => fn ($q) => $q->orderByDesc('created_at'),
            ])
            ->get();

        $releaseOrders = ReleaseOrderResource::getEloquentQuery()
            ->whereIn('release_orders.status', $statuses)
            ->with(['dealer', 'main'])
            ->get();
        $stockOrders = StockOrder::query()
            ->whereIn('orders.status', $statuses)
            ->with(['supplier', 'main'])
            ->get();

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
                'created_at' => $po->created_at,
                'delivered_at' => $po->delivered_at,
                'status' => $po->getStatus()?->value,
            ]);
        }

        foreach ($releaseOrders as $ro) {
            $id = 'ro-' . $ro->getId();
            $deliveredAt = $ro->getStatus() === ReleaseOrderStatus::Delivered
                ? $ro->updated_at
                : null;
            $rows->push([
                $recordKey => $id,
                'key' => $id,
                'kind' => 'ro',
                'purchaseOrder' => null,
                'releaseOrder' => $ro,
                'created_at' => $ro->created_at,
                'delivered_at' => $deliveredAt,
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
                'created_at' => $so->created_at,
                'delivered_at' => null,
                'status' => $so->getStatus()?->value,
            ]);
        }

        $desc = $sortDirection !== 'asc';
        $rows = $rows->sortBy(
            fn (array $row): mixed => $this->combinedListSortValue($row, $sortColumn),
            SORT_REGULAR,
            $desc
        );

        return $rows->values();
    }

    protected function combinedListSortValue(array $row, ?string $sortColumn): mixed
    {
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
        if ($sortColumn === 'delivered_at' || $sortColumn === 'deliveredAt') {
            $d = $row['delivered_at'] ?? null;

            return $d instanceof Carbon ? $d->timestamp : 0;
        }

        return $row['created_at']?->timestamp ?? 0;
    }

    protected function makeCombinedPurchaseReleaseTable(): Table
    {
        $table = parent::makeTable();

        return $table
            ->recordAction(fn (Model|array $record, Table $t): ?string => null)
            ->recordUrl(fn (Model|array $record, Table $t): ?string => null);
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if (is_array($record) && isset($record['key'])) {
            return (string) $record['key'];
        }

        return parent::getTableRecordKey($record);
    }
}

<?php

namespace App\Models;

use App\Casts\PurchaseOrderStatusCast;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hydrated from {@see \App\Filament\Resources\SupplierResource\Widgets\SupplierPurchaseDocumentsWidget}
 * union of purchase orders and stock orders; not backed by a physical table.
 *
 * @property string $id Synthetic key: purchase-{id} or stock-{id}
 * @property string $source_type purchase|stock
 * @property int $source_id Primary key on source table
 * @property string $document_number
 * @property PurchaseOrderType|null $purchase_order_type
 * @property Carbon|null $sent_at
 * @property PurchaseOrderStatus|null $status
 * @property Carbon|null $created_at
 */
class SupplierPurchaseDocumentTableRow extends Model
{
    /**
     * Must match the alias passed to {@see \Illuminate\Database\Eloquent\Builder::fromSub()} so ORDER BY / Filament
     * tie-break sorts qualify columns on the subquery, not the inferred table name.
     */
    protected $table = 'supplier_purchase_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'status' => PurchaseOrderStatusCast::class,
            'purchase_order_type' => PurchaseOrderType::class,
        ];
    }
}

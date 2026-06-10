<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PurchaseOrderConfirmation
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property string|null $pdf_path
 * @property Carbon|null $expected_delivery_date
 * @property Carbon|null $email_received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @method static Builder|PurchaseOrderConfirmation newModelQuery()
 * @method static Builder|PurchaseOrderConfirmation newQuery()
 * @method static Builder|PurchaseOrderConfirmation query()
 * @method static Builder|PurchaseOrderConfirmation whereCreatedAt($value)
 * @method static Builder|PurchaseOrderConfirmation whereEmailReceivedAt($value)
 * @method static Builder|PurchaseOrderConfirmation whereExpectedDeliveryDate($value)
 * @method static Builder|PurchaseOrderConfirmation whereId($value)
 * @method static Builder|PurchaseOrderConfirmation wherePdfPath($value)
 * @method static Builder|PurchaseOrderConfirmation wherePurchaseOrderId($value)
 * @method static Builder|PurchaseOrderConfirmation whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PurchaseOrderConfirmation extends Model
{
    use HasFactory;

    public const STORAGE_DIRECTORY = 'purchase_orders';

    protected $table = 'purchase_order_confirmations';

    protected $fillable = [
        'purchase_order_id',
        'pdf_path',
        'expected_delivery_date',
        'email_received_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_delivery_date' => 'datetime',
            'email_received_at' => 'datetime',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function getDaysSinceReceivedAttribute(): ?int
    {
        $receivedAt = $this->email_received_at ?? $this->created_at;

        if ($receivedAt === null) {
            return null;
        }

        return abs((int) now()->diffInDays($receivedAt));
    }
}

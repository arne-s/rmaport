<?php

namespace App\Models;

use App\Models\Order\BaseOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class StatusChange extends Model
{
    protected $table = 'status_changes';

    protected static function booted(): void
    {
        static::created(function (StatusChange $model): void {
            if ($model->order_product_id !== null) {
                $orderProduct = OrderProduct::query()->find($model->order_product_id);
                $orderProduct?->statusChange($model);

                return;
            }

            // Later: order_id → BaseOrder, enz.
        });
    }

    protected $fillable = [
        'from_status',
        'to_status',
        'changed_by',
        'meta',
        'order_id',
        'order_product_id',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function order()
    {
        return $this->belongsTo(BaseOrder::class);
    }

    public function orderProduct()
    {
        return $this->belongsTo(OrderProduct::class);
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function getFromStatus(): ?string
    {
        return $this->from_status;
    }

    public function setFromStatus(?string $from_status)
    {
        $this->from_status = $from_status;
        return $this;
    }

    public function getToStatus(): string
    {
        return $this->to_status;
    }

    public function setToStatus(string $to_status)
    {
        $this->to_status = $to_status;
        return $this;
    }

    public function getChangedBy(): ?int
    {
        return $this->changed_by;
    }

    public function setChangedBy(?int $changed_by)
    {
        $this->changed_by = $changed_by;
        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta)
    {
        $this->meta = $meta;
        return $this;
    }

    public function getOrderId(): ?int
    {
        return $this->order_id;
    }

    public function setOrderId(?int $order_id)
    {
        $this->order_id = $order_id;
        return $this;
    }

    public function getOrderProductId(): ?int
    {
        return $this->order_product_id;
    }

    public function setOrderProductId(?int $order_product_id)
    {
        $this->order_product_id = $order_product_id;
        return $this;
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }
}

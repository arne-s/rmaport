<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $table = 'stock_movements';

    protected $fillable = [
        'product_id',
        'quantity',
        'type', // purchase, sale, adjustment, return_in, return_out
        'ref_table',
        'ref_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getProductId(): int
    {
        return $this->product_id;
    }

    public function setProductId(int $productId): void
    {
        $this->product_id = $productId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getRefTable(): string
    {
        return $this->ref_table;
    }

    public function setRefTable(string $refTable): void
    {
        $this->ref_table = $refTable;
    }

    public function getRefId(): int
    {
        return $this->ref_id;
    }

    public function setRefId(int $refId): void
    {
        $this->ref_id = $refId;
    }
}

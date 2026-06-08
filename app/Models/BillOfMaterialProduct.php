<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $bill_of_material_id
 * @property int $product_id
 * @property float $qty
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\BillOfMaterial $billOfMaterial
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct whereBillOfMaterialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct Qty($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BillOfMaterialProduct whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class BillOfMaterialProduct extends Pivot
{
    public $incrementing = true;

    protected $fillable = [
        'bill_of_material_id',
        'product_id',
        'qty',
        'sort',
    ];

    public function billOfMaterial()
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function setQty(float $qty): self
    {
        $this->qty = $qty;
        return $this;
    }
}

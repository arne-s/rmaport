<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\BillOfMaterial
 *
 * @property int $id
 * @property string $name
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
 * @property-read int|null $products_count
 */
class BillOfMaterial extends Model
{
    protected $fillable = [
        'name',
        'created_by',
    ];


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products()
    {
        return $this->belongsToMany(BillOfMaterialProduct::class, 'bill_of_material_product', 'bill_of_material_id', 'product_id')
            ->withPivot('qty')
            ->using(BillOfMaterialProduct::class);
    }

    public function billOfMaterialProducts()
    {
        return $this->hasMany(BillOfMaterialProduct::class, 'bill_of_material_id')->orderBy('sort');
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCreatedBy(): int
    {
        return $this->created_by;
    }

    public function setCreatedBy(int $createdBy): self
    {
        $this->created_by = $createdBy;
        return $this;
    }
}

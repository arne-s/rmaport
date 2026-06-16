<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $import_id
 * @property int|null $customer_id
 * @property int|null $source_id
 * @property string|null $source_description
 * @property string|null $customer_nr
 * @property string|null $customer_order_id
 * @property string|null $reference
 * @property string|null $assignment_nr
 * @property string|null $ean_nr
 * @property string|null $product_name
 * @property bool $is_doa
 * @property Carbon|null $purchase_date
 * @property Carbon|null $return_date
 * @property string|null $return_reason
 * @property string|null $accessories
 */
class ImportRow extends Model
{
    protected $fillable = [
        'import_id',
        'customer_id',
        'source_id',
        'source_description',
        'customer_nr',
        'customer_order_id',
        'reference',
        'assignment_nr',
        'ean_nr',
        'product_name',
        'is_doa',
        'purchase_date',
        'return_date',
        'return_reason',
        'accessories',
    ];

    protected function casts(): array
    {
        return [
            'is_doa' => 'boolean',
            'purchase_date' => 'date',
            'return_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ImportRow $row): void {
            if ($row->customer_id !== null || $row->source_id === null) {
                return;
            }

            $row->customer_id = Source::query()
                ->whereKey($row->source_id)
                ->value('customer_id');
        });
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function rma(): HasOne
    {
        return $this->hasOne(Rma::class, 'import_row_id');
    }
}

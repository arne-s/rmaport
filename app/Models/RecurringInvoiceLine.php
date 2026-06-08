<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $recurring_invoice_id
 * @property int $sort
 * @property int $product_id
 * @property string $qty
 * @property string $company_sales_price_discount_percentage
 * @property string|null $attribute_summary_basic
 * @property string $company_purchase_price_base
 * @property string $company_sales_price_base
 * @property int|null $supplier_id
 * @property string $value
 */
class RecurringInvoiceLine extends Model
{
    protected static function booted(): void
    {
        static::creating(function (RecurringInvoiceLine $line): void {
            if ($line->recurring_invoice_id === null) {
                return;
            }
            if ($line->sort > 0) {
                return;
            }
            $max = static::query()->where('recurring_invoice_id', $line->recurring_invoice_id)->max('sort');
            $line->sort = (int) $max + 1;
        });
    }

    protected $fillable = [
        'recurring_invoice_id',
        'sort',
        'product_id',
        'qty',
        'company_sales_price_discount_percentage',
        'attribute_summary_basic',
        'company_purchase_price_base',
        'company_sales_price_base',
        'supplier_id',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'company_sales_price_discount_percentage' => 'decimal:2',
            'company_purchase_price_base' => 'decimal:4',
            'company_sales_price_base' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}

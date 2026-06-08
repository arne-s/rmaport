<?php

namespace App\Models;

use App\Enums\InvoiceCaption;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hydrated from {@see \App\Filament\Resources\CustomerResource\Widgets\CompanyDocumentsWidget}
 * union of base orders and media (packing slips); not backed by a physical table.
 *
 * @property string $id Synthetic key: order-{id}, media-{id}, or exact-{id}
 * @property string $source_type order|media|exact_document
 * @property int $source_id Primary key on source table
 * @property Carbon|null $sent_at
 * @property string $type Document type (quote, order, deposit_invoice, invoice, credit_invoice, stock_order, packing_slip, postnl_label, postnl_retour_label, delivery_note, other)
 * @property InvoiceCaption|null $caption Invoice caption when source is an order row
 * @property string|null $uid
 * @property int|null $rev
 * @property string|null $subtype
 * @property int|null $main_id
 * @property string|null $main_uid
 * @property string|null $main_reference_internal
 * @property int|null $company_id
 * @property int|null $customer_id
 * @property string|null $status
 * @property int|null $is_cancelled
 * @property string|null $file_name
 * @property Carbon|null $created_at
 */
class CompanyDocumentTableRow extends Model
{
    /**
     * Must match the alias passed to {@see \Illuminate\Database\Eloquent\Builder::fromSub()} so ORDER BY / Filament
     * tie-break sorts qualify columns on the subquery, not the inferred table name.
     */
    protected $table = 'company_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'caption' => InvoiceCaption::class,
            // NOTE: We intentionally do NOT cast 'type' to OrderType enum
            // because packing slips use 'packing_slip' which is not in the enum
        ];
    }

    /**
     * Same formatting as {@see \App\Models\Order\BaseOrder::getUidFormatted()}.
     */
    public function getUidFormatted(): string
    {
        $uid = (string) ($this->uid ?? '');
        if ($uid === '') {
            return '';
        }

        $typeValue = $this->type instanceof \BackedEnum ? $this->type->value : (string) ($this->type ?? '');
        $rev = (int) ($this->rev ?? 0);

        if ($typeValue === OrderType::Quote->value && $rev >= 1) {
            return $uid . ' / ' . $rev;
        }

        if (! in_array($typeValue, [OrderType::Quote->value, OrderType::Order->value], true) && $rev > 1) {
            return $uid . '/' . $rev;
        }

        return $uid;
    }

    /**
     * Packing slips don't have a cancelled status, always return null for them.
     * For orders, return the is_cancelled attribute if it exists.
     */
    public function getIsCancelled(): ?int
    {
        if ($this->source_type === 'media') {
            return null;
        }

        return $this->is_cancelled ?? null;
    }
}

<?php

namespace App\Models;

use App\Models\Order\Order;
use App\Models\OrderProduct;
use App\Models\User;
use App\Support\PackingSlipDocumentSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackingSlip extends Model
{
    protected $fillable = [
        'uid',
        'order_id',
        'author_id',
        'signature',
        'comment',
        'reference',
        'checklist',
        'checklist_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checklist' => 'array',
        ];
    }

    public static function getNextUid(): string
    {
        return PackingSlipDocumentSequence::next();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderProducts(): HasMany
    {
        return $this->hasMany(OrderProduct::class, 'packing_slip_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

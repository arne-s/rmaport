<?php

namespace App\Models;

use App\Enums\PriceChangeMethod;
use App\Enums\PriceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PriceChangeLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'product_id',
        'value_from',
        'value_to',
        'action',
        'method',
        'user_id',
        'comment',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PriceType::class,
            'value_from' => 'decimal:4',
            'value_to' => 'decimal:4',
            'method' => PriceChangeMethod::class,
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function buildActionLabel(?float $from, ?float $to, ?string $context = null): string
    {
        if ($context !== null && $context !== '') {
            return $context;
        }

        if ($from === null || $to === null) {
            return 'set';
        }

        if ((float) $from === 0.0) {
            return 'set';
        }

        $deltaPercentage = (($to - $from) / abs($from)) * 100;
        if ((float) $deltaPercentage === 0.0) {
            return 'set';
        }

        $rounded = round($deltaPercentage, 2);
        $formatted = rtrim(rtrim(number_format(abs($rounded), 2, '.', ''), '0'), '.');

        return ($rounded > 0 ? '+' : '-') . $formatted . '%';
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }
}

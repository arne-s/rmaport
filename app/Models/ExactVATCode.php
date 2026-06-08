<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExactVATCode extends Model
{
    const VAT_TRANSACTION_TYPES = [
        'sales' => 'S',
        'purchase' => 'P',
        'both' => 'B',
    ];
    const DEFAULT_SALES_VAT_CODE = '1';

    protected $table = 'exact_vat_codes';

    protected $fillable = [
        'guid',
        'code',
        'name',
        'percentage',
        'type',
        'vat_transaction_type',
        'is_blocked',
        'gl_to_claim',
        'gl_to_pay',
        'modified',
    ];

    protected function casts()
    {
        return [
            'is_blocked' => 'boolean',
            'modified' => 'datetime',
        ];
    }

    public static function getPurchaseVatCodes()
    {
        return self::whereIn('vat_transaction_type', [self::VAT_TRANSACTION_TYPES['purchase'], self::VAT_TRANSACTION_TYPES['both']])
            ->whereIsBlocked(false)
            ->get();
    }

    public static function getSalesVatCodes()
    {
        return self::whereIn('vat_transaction_type', [self::VAT_TRANSACTION_TYPES['sales'], self::VAT_TRANSACTION_TYPES['both']])
            ->whereIsBlocked(false)
            ->get();
    }

    public static function convertToOptions($vatCode)
    {
        return $vatCode
            ->sortBy('code')
            ->mapWithKeys(fn ($vatCode) => [
                $vatCode->code => "{$vatCode->code} : {$vatCode->name}",
            ])->toArray();
    }

    public static function getPurchaseVatCodesAsOptions()
    {
        $accounts = self::getPurchaseVatCodes();
        return self::convertToOptions($accounts);
    }

    public static function getSalesVatCodesAsOptions()
    {
        $accounts = self::getSalesVatCodes();
        return self::convertToOptions($accounts);
    }

    /**
     * Return VAT percentage on a 0–100 scale (e.g. 21 for 21%), matching order line storage and PDF totals.
     */
    public function percentageAsPercent(): float
    {
        $pct = (float) $this->percentage;

        return $pct <= 1 ? $pct * 100 : $pct;
    }

    public static function percentageAsPercentForCode(?string $code, float $default = 21.0): float
    {
        if ($code === null || $code === '') {
            return $default;
        }

        $vatCode = self::query()->where('code', $code)->first();

        return $vatCode !== null ? $vatCode->percentageAsPercent() : $default;
    }
}

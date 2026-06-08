<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExactPaymentCondition extends Model
{
    const DEFAULT_PAYMENT_CONDITION_CODE = '14';

    const NOT_APPLICABLE_CODE = '0D';

    /** @var array<int, string> */
    const ALLOWED_CODES = ['0D', '1D', '07', '14', '21', '30', '45', '49', '60'];

    protected $table = 'exact_payment_conditions';

    protected $fillable = [
        'guid',
        'code',
        'name',
        'payment_days',
        'payment_end_of_months',
        'payment_method',
        'modified',
    ];

    protected function casts()
    {
        return [
            'modified' => 'datetime',
        ];
    }

    public static function convertToOptions($paymentConditions)
    {
        $order = array_flip(self::ALLOWED_CODES);

        return $paymentConditions
            ->sortBy(fn ($item) => $order[$item->code] ?? PHP_INT_MAX)
            ->mapWithKeys(fn ($paymentCondition) => [
                $paymentCondition->code => "{$paymentCondition->code} : {$paymentCondition->name}",
            ])->toArray();
    }

    public function scopeAllowed($query)
    {
        return $query->whereIn('code', self::ALLOWED_CODES);
    }

    public static function getPaymentConditionsAsOptions()
    {
        $paymentConditions = self::query()->allowed()->get();
        return self::convertToOptions($paymentConditions);
    }
}

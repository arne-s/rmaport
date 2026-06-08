<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Builder;

class DepositInvoice extends BaseOrder
{
    const DEFAULT_DEPOSIT_PERCENTAGE = 50;

    protected static function boot()
    {

        parent::boot();
        static::addGlobalScope('type',
            fn(Builder $builder) => $builder->where('type', 'deposit_invoice'));
    }

    public function setInitialDepositAmount(): DepositInvoice
    {
        $deposit = $this->getCompanySalesPriceTotalIncVat() / 100 * self::DEFAULT_DEPOSIT_PERCENTAGE;

        $this->setDepositAmount($deposit);
        $this->setPaymentAmount($deposit);
        $this->setPaymentPercentage(self::DEFAULT_DEPOSIT_PERCENTAGE);

        return $this;
    }


    public function isPaid(): bool
    {
        return !is_null($this->getPaymentAt());
    }
}

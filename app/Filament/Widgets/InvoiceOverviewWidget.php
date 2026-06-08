<?php

namespace App\Filament\Widgets;

use App\Enums\OrderGeneralStatus;
use App\Models\Order\BaseOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class InvoiceOverviewWidget extends BaseWidget
{
    public mixed $totalOrdersCount = 0;
    public mixed $totalOrdersSum = 0;
    public mixed $margin = 0;
    public mixed $purchasePrice = 0;

    protected $listeners = ['update-invoice-widget' => 'setData'];

    public function setData($attributes)
    {
        $this->totalOrdersCount = $attributes['total_orders'];
        $this->totalOrdersSum = $attributes['payment_amount'];
        $this->margin = $attributes['payment_amount'] - $attributes['company_purchase_price_total'];
    }

    public function mount(): void
    {
        $query = BaseOrder::whereIn('type', ['invoice', 'deposit_invoice'])
            ->whereNotIn('status', [
                OrderGeneralStatus::Initial->value,
                OrderGeneralStatus::Draft->value
            ]);
        $this->totalOrdersCount = $query->count();
        $this->totalOrdersSum = $query->sum('payment_amount');
        $this->margin = $query->sum('payment_amount') - $query->sum('company_purchase_price_total');
        $this->purchasePrice = $query->sum('company_purchase_price_total');
    }

    public function formatMoney($value): string
    {
        return $value > 0
            ? '€ ' . number_format(round($value, 2), 2, ',', '.')
            : '€ 0,00';
    }

    protected function getCards(): array
    {

        return [];
    }
}

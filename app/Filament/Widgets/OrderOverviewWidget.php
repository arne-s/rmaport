<?php

namespace App\Filament\Widgets;

use App\Enums\OrderGeneralStatus;
use App\Filament\Support\SalesAuthorization;
use App\Models\Order\Order;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Illuminate\Support\HtmlString;

class OrderOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return SalesAuthorization::canManage();
    }

    public mixed $totalOrdersCount = 0;
    public mixed $totalCompanyPurchasePrice = 0;
    public mixed $totalCompanySalesPrice = 0;

    protected $listeners = ['update-order-widget' => 'setData'];

    public function setData($attributes)
    {
        $this->totalOrdersCount = $attributes['total_orders'];
        $this->totalCompanySalesPrice = $attributes['total_company_sales_price'];
        $this->totalCompanyPurchasePrice = $attributes['total_company_purchase_price'];
    }

    public function mount(): void {
        $query = Order::query()
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value]);

        $totalCompanyPurchasePrice = $query->sum('company_purchase_price_total');
        $totalCompanySalesPrice = $query->sum('company_sales_price_total');

        $this->totalOrdersCount = $query->count();
        $this->totalCompanyPurchasePrice = $totalCompanyPurchasePrice;
        $this->totalCompanySalesPrice = $totalCompanySalesPrice;
    }

    public function formatMoney($value): string
    {
        return $value > 0
            ? '€ ' . number_format(round($value, 2), 2, ',', '.')
            : '€ 0,00';
    }

    protected function getCards(): array
    {
        return [
            Stat::make('Aantal orders', $this->totalOrdersCount),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">omzet</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Verkoop <span class="tax">(excl. BTW)</span></span>
                        '. $this->formatMoney($this->totalCompanySalesPrice) .'
                    </div>'
                )
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">inkoop</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Inkoop <span class="tax">(excl. BTW)</span></span>
                        '. $this->formatMoney($this->totalCompanyPurchasePrice) .'
                    </div>'
                )
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">marge</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Marge <span class="tax">(excl. BTW)</span></span>
                        '. $this->formatMoney($this->totalCompanySalesPrice - $this->totalCompanyPurchasePrice) .'
                    </div>
                ')
            ),
        ];
    }
}

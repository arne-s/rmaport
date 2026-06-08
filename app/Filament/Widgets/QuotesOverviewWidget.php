<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Order\Quote;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Illuminate\Support\HtmlString;

class QuotesOverviewWidget extends BaseWidget
{
    public mixed $pendingQuotesCount = 0;
    public mixed $pendingQuotesSum = 0;
    public mixed $completedQuotesCount = 0;
    public mixed $completedQuotesSum = 0;
    public mixed $expiredQuotesCount = 0;
    public mixed $expiredQuotesSum = 0;
    public mixed $canceledQuotesCount = 0;
    public mixed $canceledQuotesSum = 0;

    protected $listeners = ['update-quotes-widget' => 'setData'];

    public function setData($attributes)
    {
        $this->pendingQuotesCount = $attributes['pending_quotes_count'];
        $this->pendingQuotesSum = $attributes['pending_quotes_sum'];
        $this->completedQuotesCount = $attributes['completed_quotes_count'];
        $this->completedQuotesSum = $attributes['completed_quotes_sum'];
        $this->expiredQuotesCount = $attributes['expired_quotes_count'];
        $this->expiredQuotesSum = $attributes['expired_quotes_sum'];
        $this->canceledQuotesCount = $attributes['canceled_quotes_count'];
        $this->canceledQuotesSum = $attributes['canceled_quotes_sum'];
    }

    public function mount(): void
    {
        $pending = Quote::query()->where('status', 'pending');

        $this->pendingQuotesCount = $pending->count();
        $this->pendingQuotesSum = $pending->sum('company_sales_price_total');
        $completed = Quote::query()->where('status', 'completed');

        $this->completedQuotesCount = $completed->count();
        $this->completedQuotesSum = $completed->sum('company_sales_price_total');

        $expired = Quote::query()->where('status', 'expired');
        $this->expiredQuotesCount = $expired->count();
        $this->expiredQuotesSum = $expired->sum('company_sales_price_total');

        $canceled = Quote::query()->where('status', 'canceled');
        $this->canceledQuotesCount = $canceled->count();
        $this->canceledQuotesSum = $canceled->sum('company_sales_price_total');
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
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Openstaand</span>'),
                new HtmlString('
                    <div class="totalsWidgetsQuantity">
                        <span>Aantal</span>
                        '.$this->pendingQuotesCount. '
                    </div>
                    <div class="totalsWidgetsvalue">
                        <span class="value">Waarde <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->pendingQuotesSum).'
                    </div>
                ')
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Gerealiseerd</span>'),
                new HtmlString('
                    <div class="totalsWidgetsQuantity">
                        <span>Aantal</span>
                        '.$this->completedQuotesCount. '
                    </div>
                    <div class="totalsWidgetsvalue">
                        <span class="value">Waarde <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->completedQuotesSum).'
                    </div>
                ')
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Verlopen</span>'),
                new HtmlString('
                    <div class="totalsWidgetsQuantity">
                        <span>Aantal</span>
                        '.$this->expiredQuotesCount. '
                    </div>
                    <div class="totalsWidgetsvalue">
                        <span class="value">Waarde <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->expiredQuotesSum).'
                    </div>
                ')
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Geannuleerd</span>'),
                new HtmlString('
                    <div class="totalsWidgetsQuantity">
                        <span>Aantal</span>
                        '.$this->canceledQuotesCount. '
                    </div>
                    <div class="totalsWidgetsvalue">
                        <span class="value">Waarde <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->canceledQuotesSum).'
                    </div>
                ')
            ),
        ];
    }
}

<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

class MainReportingTotalsWidget extends StatsOverviewWidget
{
    public mixed $saleTotal = 0;

    public mixed $purchaseFrameTotal = 0;

    public mixed $purchasePartsTotal = 0;

    public mixed $marginTotal = 0;

    public function formatMoney(mixed $value): string
    {
        $n = round((float) $value, 2);

        return $n > 0
            ? '€ ' . number_format($n, 2, ',', '.')
            : '€ 0,00';
    }

    protected function getColumns(): int | array | null
    {
        return ['@xl' => 4, '!@lg' => 2];
    }

    /** @see \App\Filament\Resources\MainReportingResource\Pages\ListMainReports::getWidgetData() */
    protected function getCachedStats(): array
    {
        return $this->getStats();
    }

    protected function getCards(): array
    {
        return [
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Verkoopprijs</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Totaal <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->saleTotal). '
                    </div>
                ')
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Inkoopprijs</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Totaal <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->purchaseFrameTotal). '
                    </div>
                ')
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Onderdelen inkoopprijs</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Totaal <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->purchasePartsTotal). '
                    </div>
                ')
            ),
            Stat::make(
                new HtmlString('<span style="top: -11px;" class="labelWidget">Marge</span>'),
                new HtmlString('
                    <div class="totalsWidgetsvalue">
                        <span class="value">Totaal <span class="tax">(excl. BTW)</span></span>
                        ' .$this->formatMoney($this->marginTotal). '
                    </div>
                ')
            ),
        ];
    }
}

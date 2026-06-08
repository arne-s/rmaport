<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Widgets\OrderOverviewWidget;
use App\Filament\Resources\Pages\ListRecords;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
    protected static ?string $breadcrumb = 'Verkooporders';

    public ?string $status = null;

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderOverviewWidget::class
        ];
    }

    protected function updateWidgets($query): void
    {
//        $totalCompanySalesPrice = $query->sum('company_sales_price_total');
//        $totalCompanyPurchasePrice = $query->sum('company_purchase_price_total');
//
//        $this->dispatch('update-order-widget', [
//            'total_orders' => $query->count('id'),
//            'total_company_purchase_price' => $totalCompanyPurchasePrice,
//            'total_company_sales_price' => $totalCompanySalesPrice,
//        ]);
    }

    protected function applySearchToTableQuery(Builder $query): Builder
    {
        $parent = parent::applySearchToTableQuery($query);
        $this->updateWidgets($query);
        return $parent;
    }

    protected function applyColumnSearchToTableQuery(Builder $query): Builder
    {
        $parent = parent::applyColumnSearchToTableQuery($query);
        $this->updateWidgets($query);
        return $parent;
    }

    public function getHeader(): ?View
    {
        return view('filament.components.back-to-overview-with-topbar-breadcrumbs', [
            'title' => 'Dashboard',
            'url' => route('filament.app.pages.dashboard'),
            'class' => 'quote-overview-back mt-3 mb-1',
            'breadcrumbs' => Filament::hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Verkoop',
            route('filament.app.resources.orders.index') => 'Orders',
        ];
    }
}

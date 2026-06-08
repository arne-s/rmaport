<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected static ?string $title = 'Facturen';
    protected static ?string $breadcrumb = 'Facturen';

    // public function getDefaultTableRecordsPerPageSelectOption(): int { return 50; }

    // protected function updateWidgets(Builder $query): void
    // {
    //     $purchasePrice = (clone $query)->selectRaw(' SUM(purchase_price * payment_percentage / 100) AS p')
    //         ->pluck('p')->first();
    //     $cartPrice = (clone $query)->selectRaw(' SUM(total_price * payment_percentage / 100) AS p')
    //         ->pluck('p')->first();

    //     $this->dispatch('update-invoice-widget', [
    //         'total_orders' => $query->count(),
    //         'total_price' => $cartPrice,
    //         'margin' => $purchasePrice - $cartPrice,
    //         'purchase_price' => $purchasePrice,
    //     ]);
    // }

    // protected function applySearchToTableQuery(Builder $query): Builder
    // {
    //     $parent = parent::applySearchToTableQuery($query);
    //     $this->updateWidgets($query);
    //     return $parent;
    // }

    // protected function applyColumnSearchToTableQuery(Builder $query): Builder
    // {
    //     $parent = parent::applyColumnSearchToTableQuery($query);
    //     $this->updateWidgets($query);
    //     return $parent;
    // }

    // public function getHeaderWidgetsColumns(): int|array
    // {
    //     return 4;
    // }

    // protected function getHeaderWidgets(): array
    // {
    //     return [
    //         InvoiceOverviewWidget::class
    //     ];
    // }
}

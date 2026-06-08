<?php

namespace App\Filament\Resources\CreditInvoiceResource\Pages;

use App\Filament\Resources\CreditInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditInvoices extends ListRecords
{
    protected static string $resource = CreditInvoiceResource::class;

    protected static ?string $title = 'Creditfacturen';
    protected static ?string $breadcrumb = 'Creditfacturen';

    protected function getHeaderActions(): array
    {
        return [];
    }
}

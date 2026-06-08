<?php

namespace App\Filament\Resources\RecurringInvoices\Pages;

use App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListRecurringInvoices extends ListRecords
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected static ?string $title = 'Abonnementen';

    protected static ?string $breadcrumb = 'Overzicht';
}

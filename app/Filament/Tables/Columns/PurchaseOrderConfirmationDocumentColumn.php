<?php

namespace App\Filament\Tables\Columns;

use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

class PurchaseOrderConfirmationDocumentColumn extends TextColumn
{
    use CanBeEmpty;

    protected string $view = 'filament.tables.columns.purchase-order-confirmation-document-column';
}

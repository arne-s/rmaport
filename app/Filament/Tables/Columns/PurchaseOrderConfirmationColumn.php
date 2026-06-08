<?php

namespace App\Filament\Tables\Columns;

use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

class PurchaseOrderConfirmationColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.purchase-order-confirmation-column';

    public function getModel()
    {
        return match ($this->getName()) {
            'purchaseOrder.latestConfirmation.pdf_path' => $this->record->purchaseOrder,
            'latestConfirmation.pdf_path', => $this->record,
            default => $this->record,
        };
    }
}

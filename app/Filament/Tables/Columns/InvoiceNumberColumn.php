<?php

namespace App\Filament\Tables\Columns;

use App\Enums\PaymentTerms;
use App\Enums\PurchaseInvoiceRowType;
use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

class InvoiceNumberColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.invoice-number-column';

    public function getModel()
    {
        return match ($this->getName()) {
            'quote.uid' => $this->record->quote,
            'order.uid' => $this->record->order,
            'purchaseOrderInvoice.orderUid' => $this->record->_rowType !== PurchaseInvoiceRowType::InvoiceRowChild
                ? $this->record
                : $this->record->purchaseOrder->order,
            'invoice.uid' => $this->record->invoice,
            'deposit_invoice.uid' => $this->record->depositInvoice,
            'deposit_invoice.sent_at' => $this->record->depositInvoice?->sent_at,
            'credit_invoice.uid' => $this->record,
            default => $this->record,
        };
    }

    public function shouldShowPayLater(): bool
    {
        return ($this->record?->type === 'deposit_invoice' || $this->getName() === 'deposit_invoice.uid')
            && ! PaymentTerms::requiresDepositInvoice($this->record?->company?->getPaymentTerms());
    }
}

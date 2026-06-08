<?php

namespace App\Filament\Tables\Columns;

use App\Enums\PaymentTerms;
use App\Models\Order\DepositInvoice;
use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

class PaidColumn extends TextColumn
{
    use CanBeEmpty;

    protected string $view = 'filament.tables.columns.paid-column';

    public function getModel()
    {
        return match ($this->getName()) {
//            'payment' => match ($this->record?->getType()) {
//                'deposit_invoice' => $this->record->getOrder()?->depositInvoice,
//                'invoice' => $this->record,
//            },
            'invoice.payment' => $this->record?->getInvoice(),
            'deposit_invoice.payment' => $this->resolveDepositInvoiceModel(),
            'paid_at' => $this->record,
            default => $this->record,
        };
    }

    protected function resolveDepositInvoiceModel(): ?DepositInvoice
    {
        $depositInvoice = $this->record?->depositInvoice;
        if ($depositInvoice instanceof DepositInvoice) {
            return $depositInvoice;
        }

        return null;
    }

    public function shouldShowNotApplicable(): bool
    {
        if ($this->record instanceof DepositInvoice) {
            return false;
        }

        if ($this->getName() === 'deposit_invoice.payment'
            && $this->resolveDepositInvoiceModel() instanceof DepositInvoice) {
            return false;
        }

        return ($this->record?->type === 'deposit_invoice' || $this->getName() === 'deposit_invoice.payment')
            && ! PaymentTerms::requiresDepositInvoice(($this->record?->billingCustomer ?? $this->record?->customer)?->getPaymentTerms());
    }
}

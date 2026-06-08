<?php

namespace App\Filament\Tables\Columns;

use App\Enums\PaymentTerms;
use App\Models\Order\BaseOrder;
use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

/** @property BaseOrder $record */
class OrderNumberColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.portal.order-number-column';

    /**
     * Whether to display the date in the column.
     *
     * @var bool
     */
    protected bool $displayDate = true;

    /**
     * Whether to hide the hash symbol (#) in the column.
     *
     * @var bool
     */
    protected bool $hideHash = false;


    /**
     * Whether to show cancelled status.
     *
     * @var bool
     */
    protected bool $showCancelled = true;


    public function displayDate(bool $condition = true): static
    {
        $this->displayDate = $condition;
        return $this;
    }

    public function getDisplayDate(): bool
    {
        return $this->displayDate;
    }

    public function hideHash(bool $condition = true): static
    {
        $this->hideHash = $condition;
        return $this;
    }

    public function getHideHash(): bool
    {
        return $this->hideHash;
    }

    public function showCancelled(bool $condition = true): static
    {
        $this->showCancelled = $condition;
        return $this;
    }

    public function getShowCancelled(): bool
    {
        return $this->showCancelled;
    }

    public function getModel()
    {
        return match ($this->getName()) {
            'quote.uid' => $this->record->quote,
            'quoteCompany.uid' => $this->record->quoteCompany,
            'orderCompany.uid' => $this->record->orderCompany,
            'invoice.uid' => $this->record->invoice,
            'deposit_invoice.uid' => $this->record->depositInvoice,
            'deposit_invoice.sent_at' => $this->record->depositInvoice->sent_at,
            'invoice.credit_invoice.uid' => $this->record->invoice?->creditInvoice,
            'deposit_invoice.credit_invoice.uid' => $this->record->depositInvoice?->creditInvoice,
            default => $this->record,
        };
    }

    public function isCancelled(): bool
    {
        $model = $this->getModel();
        return $model?->getType()?->value !== 'order' && ($model?->getIsCancelled() || $model?->order?->getIsCancelled() || $this->record?->getIsCancelled());
    }

    public function shouldShowPayLater(): bool
    {
        return ($this->record?->type === 'deposit_invoice' || $this->getName() === 'deposit_invoice.uid')
            && ! PaymentTerms::requiresDepositInvoice($this->record?->company?->getPaymentTerms());
    }
}

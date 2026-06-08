<?php

namespace App\Filament\Tables\Columns;

use App\Models\Order\BaseOrder;
use App\Traits\Columns\CanBeEmpty;
use Closure;
use Filament\Tables\Columns\TextColumn;

/** @property BaseOrder $record */
class OrderNumberPageColumn extends TextColumn
{
    use CanBeEmpty;

    protected string $view = 'filament.tables.columns.order-number-page-column';

    protected string | Closure | null $pendingOrderNumberLabel = null;

    protected bool $linkOnly = false;

    public function linkOnly(bool $linkOnly = true): static
    {
        $this->linkOnly = $linkOnly;

        return $this->viewData([
            'showDownload' => ! $linkOnly,
            'displayDate' => false,
        ]);
    }

    public function isLinkOnly(): bool
    {
        return $this->linkOnly;
    }

    public function pendingOrderNumberLabel(string | Closure | null $label): static
    {
        $this->pendingOrderNumberLabel = $label;

        return $this;
    }

    public function getPendingOrderNumberLabel(): string
    {
        $evaluated = $this->evaluate($this->pendingOrderNumberLabel);

        if (is_string($evaluated) && $evaluated !== '') {
            return $evaluated;
        }

        return 'In behandeling...';
    }

    public function getModel(): mixed
    {
        return match ($this->getName()) {
            'quote.uid' => $this->record->quote,
            'order.uid' => $this->record->order,
            'invoice.uid' => $this->record->invoice,
            'deposit_invoice.uid' => $this->record->depositInvoice,
            'deposit_invoice.sent_at' => $this->record->depositInvoice->sent_at,
            'credit_invoice.uid' => $this->record,
            default => $this->record,
        };
    }
}

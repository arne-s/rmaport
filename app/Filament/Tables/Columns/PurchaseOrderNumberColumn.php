<?php

namespace App\Filament\Tables\Columns;

use App\Models\Order\BaseOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderConfirmation;
use App\Models\PurchaseOrderInvoice;
use App\Support\NavigationLink;
use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

/** @property BaseOrder $record */
class PurchaseOrderNumberColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.purchase-order-number-column';

    protected bool $linkOnly = false;

    /**
     * Alleen blauwe link tonen (zoals Document # op /purchase-orders), zonder download-icoon.
     */
    public function linkOnly(bool $linkOnly = true): static
    {
        $this->linkOnly = $linkOnly;

        return $this->viewData([
            'showDownload' => ! $linkOnly,
            'linkClass' => $linkOnly ? 'openDocument '.NavigationLink::CSS_CLASS : 'openDocument',
            'displayDate' => false,
        ]);
    }

    public function getModel(): mixed
    {
        return match ($this->getName()) {
            'reference_number' => $this->record,
            'purchaseOrder.reference_number', 'orderable.reference_number' => $this->resolvePurchaseOrderForDisplay(),
            default => $this->record instanceof PurchaseOrder
                ? $this->record
                : $this->resolvePurchaseOrderForDisplay(),
        };
    }

    private function resolvePurchaseOrderForDisplay(): mixed
    {
        if ($this->record instanceof PurchaseOrderConfirmation) {
            return $this->record->purchaseOrder;
        }

        if ($this->record instanceof PurchaseOrderInvoice) {
            return $this->record->activePurchaseOrder();
        }

        if ($this->record instanceof PurchaseOrder) {
            return $this->record;
        }

        return $this->record->purchaseOrder ?? $this->record;
    }
}

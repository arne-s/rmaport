<?php

namespace App\Filament\Tables\Columns;

use App\Enums\PurchaseInvoiceRowType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use App\Traits\Columns\CanBeEmpty;
use Closure;
use Filament\Tables\Columns\TextColumn;

/** @property BaseOrder $record */
class OrderNumberPageColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.order-number-page-column';

    protected string | Closure | null $pendingOrderNumberLabel = null;

    protected bool $linkOnly = false;

    /**
     * Alleen blauwe link tonen (zoals op /purchase-orders), zonder download-icoon.
     */
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

    /**
     * Tekst wanneer er geen ordernummer getoond kan worden (geen koppeling, concept, of nog geen uid).
     */
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
            'purchaseOrderInvoice.orderUid' => $this->resolvePurchaseOrderInvoiceOrderUidModel(),
            'purchaseOrder.order.uid', 'orderable.order.uid' => $this->resolveLinkedOrderFromPurchaseOrderParent(),
            'invoice.uid' => $this->record->invoice,
            'deposit_invoice.uid' => $this->record->depositInvoice,
            'deposit_invoice.sent_at' => $this->record->depositInvoice->sent_at,
            'credit_invoice.uid' => $this->record,
            default => $this->record,
        };
    }

    /**
     * Sales order model for the order number column (margin overview: stub Order + PO, or invoice row + PO).
     */
    private function resolvePurchaseOrderInvoiceOrderUidModel(): mixed
    {
        $record = $this->record;

        if ($record instanceof PurchaseOrderInvoice) {
            $purchaseOrder = $record->activePurchaseOrder();

            return $this->linkOnly
                ? $this->resolveMainFromPurchaseOrder($purchaseOrder)
                : $this->resolveOrderFromPurchaseOrder($purchaseOrder);
        }

        if ($record instanceof Order
            && ($record->_rowType ?? null) === PurchaseInvoiceRowType::OrderRowChild
            && $record->purchaseOrder instanceof PurchaseOrder
        ) {
            return $record->purchaseOrder->order ?? $record;
        }

        return $record->_rowType !== PurchaseInvoiceRowType::InvoiceRowChild
            ? $record
            : $record->purchaseOrder->order;
    }

    /**
     * Resolve sales order or main from a parent record that belongs to a purchase order (confirmation, invoice, …).
     */
    private function resolveLinkedOrderFromPurchaseOrderParent(): mixed
    {
        $purchaseOrder = $this->resolvePurchaseOrderFromRecord();

        if ($purchaseOrder === null) {
            return null;
        }

        if ($this->linkOnly) {
            return $this->resolveMainFromPurchaseOrder($purchaseOrder);
        }

        return $this->resolveOrderFromPurchaseOrder($purchaseOrder);
    }

    private function resolveMainFromPurchaseOrder(?PurchaseOrder $purchaseOrder): mixed
    {
        if ($purchaseOrder === null) {
            return null;
        }

        if ($purchaseOrder->relationLoaded('main')) {
            $main = $purchaseOrder->getRelation('main');
            if ($main instanceof BaseOrder) {
                return $main;
            }
        }

        return $purchaseOrder->main ?? $purchaseOrder->order?->main;
    }

    private function resolveOrderFromPurchaseOrder(?PurchaseOrder $purchaseOrder): mixed
    {
        if ($purchaseOrder === null) {
            return null;
        }

        if ($purchaseOrder->relationLoaded('order')) {
            $order = $purchaseOrder->getRelation('order');
            if ($order instanceof BaseOrder) {
                return $order;
            }
        } elseif ($purchaseOrder->order instanceof BaseOrder) {
            return $purchaseOrder->order;
        }

        if ($purchaseOrder->relationLoaded('main')) {
            $main = $purchaseOrder->getRelation('main');
            if ($main instanceof BaseOrder) {
                return $main;
            }
        }

        return $purchaseOrder->main ?? $purchaseOrder->order?->main;
    }

    private function resolvePurchaseOrderFromRecord(): ?PurchaseOrder
    {
        return match (true) {
            $this->record instanceof PurchaseOrder => $this->record,
            $this->record instanceof PurchaseOrderInvoice => $this->record->activePurchaseOrder(),
            default => $this->record->purchaseOrder ?? null,
        };
    }
}

<?php

namespace App\Filament\Tables\Columns;

use App\Enums\PurchaseInvoiceRowType;
use App\Models\Order\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoice;
use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

class OrderMarginsColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    // protected string $view = 'filament.tables.columns.reporting-order-number-column';

    protected function setUp(): void
    {
        $this->view('filament.tables.columns.download-action', function (Model $record): array {
            $orderId = $this->resolveMarginsOrderId($record);

            return [
                'label' => 'Marge',
                'modalId' => 'open-order-margins',
                'orderId' => $orderId,
                'downloadLink' => fn (): string => route('documents.orderMarginsDownload', ['orderId' => $orderId]),
            ];
        });
    }

    /**
     * Sales order id for margin document URLs; for invoice rows, resolve via linked purchase order.
     */
    private function resolveMarginsOrderId(Model $record): int|string
    {
        if ($record instanceof PurchaseOrderInvoice) {
            $orderable = $record->orderable;
            if ($orderable instanceof PurchaseOrder) {
                $oid = $orderable->getOrderId();
                if ($oid !== null) {
                    return $oid;
                }

                $linkedOrder = $orderable->order;
                if ($linkedOrder !== null) {
                    return $linkedOrder->getKey();
                }
            }

            if ($record->main_id !== null) {
                return $record->main_id;
            }
        }

        if ($record instanceof Order
            && ($record->_rowType ?? null) === PurchaseInvoiceRowType::OrderRowChild
            && $record->purchaseOrder instanceof PurchaseOrder
        ) {
            $linkedOrder = $record->purchaseOrder->order;
            if ($linkedOrder !== null) {
                return $linkedOrder->getKey();
            }

            $oid = $record->purchaseOrder->getOrderId();
            if ($oid !== null) {
                return $oid;
            }
        }

        return $record->getKey();
    }

    public function getRecord(): ?Model
    {
        if ($this->record->_rowType === PurchaseInvoiceRowType::InvoiceRow) {
            return $this->record->purchaseOrder?->order;
        }
        return $this->record;
    }
}

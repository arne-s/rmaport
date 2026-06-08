<?php

namespace App\Filament\Tables\Columns;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;

class DocumentStatusColumn extends TextColumn
{
    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.document-status-column';

    /**
     * Get the order type.
     *
     * @return string
     */
    public function getOrderType(): string
    {
        $type = $this->record->type;

        return $type instanceof OrderType ? $type->value : (string) $type;
    }

    /**
     * Get the formatted order type.
     *
     * @return string
     */
    public function getOrderTypeFormatted(): string
    {
        $type = $this->getOrderType();
        if ($type === 'quote') {
            return $this->record->getIsAdminGenerated() ? 'Beheer' : 'Portaal';
        }

        return __(sprintf('orders.type.%s', $type));
    }

    /**
     * Get the formatted order status.
     *
     * @return string
     */
    public function getOrderStatusFormatted(): string
    {
        $status = $this->getOrderStatus();
        if (empty($status)) {
            return '';
        }
        if ($status instanceof OrderStatus || $status instanceof OrderGeneralStatus) {
            return $status->getLabel() ?? '';
        }

        return __(sprintf('orders.status.%s', $status));
    }

    /**
     * Get the order status.
     *
     * @return string|OrderStatus|OrderGeneralStatus|null
     */
    public function getOrderStatus(): string|OrderStatus|OrderGeneralStatus|null
    {
        $orderType = $this->getOrderType();

        if (in_array($orderType, ['deposit_invoice', 'invoice', 'credit_invoice'], true)) {
            $resolved = $this->resolveFinancialDocumentStatusValue();

            return OrderGeneralStatus::tryFrom($resolved) ?? $this->record->status;
        }

        return match ($orderType) {
            'quote' => $this->record->status,
            'order' => $this->record->status,
            default => $this->record->status,
        };
    }

    private function resolveFinancialDocumentStatusValue(): string
    {
        if ($this->record instanceof BaseOrder) {
            return $this->record->resolveFinancialDocumentStatusValue();
        }

        $statusValue = $this->record->status instanceof OrderGeneralStatus
            ? $this->record->status->value
            : (string) ($this->record->status ?? '');

        $sentAt = $this->record->sent_at ?? null;
        if ($sentAt !== null && ! $sentAt instanceof Carbon) {
            $sentAt = Carbon::parse($sentAt);
        }

        return BaseOrder::resolveFinancialDocumentStatusValueFor(
            $this->getOrderType(),
            $statusValue,
            $sentAt instanceof Carbon ? $sentAt : null,
        );
    }
}

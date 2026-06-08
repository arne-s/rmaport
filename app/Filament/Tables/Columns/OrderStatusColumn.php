<?php

namespace App\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

class OrderStatusColumn extends TextColumn
{
    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.order-status-column';

    /**
     * Get the order type.
     *
     * @return string
     */
    public function getOrderType(): string
    {
        $type = $this->record->type;
        return $type instanceof \BackedEnum ? $type->value : (string) $type;
    }

    /**
     * Get the formatted order type.
     *
     * @return string
     */
    public function getOrderTypeFormatted(): string
    {

    return '-';
    }

    /**
     * Get the formatted order status.
     *
     * @return string
     */
    public function getOrderStatusFormatted(): string
    {
        $status = $this->record->status;
        $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return __(sprintf('orders.status.%s', $statusValue));
    }

    /**
     * Get the order status.
     *
     * @return string
     */
    public function getOrderStatus(): string
    {
        $status = $this->record->status;
        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }
}

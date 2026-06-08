<?php

namespace App\Filament\Tables\Columns\Portal;

use Filament\Tables\Columns\TextColumn;

class OrderStatusColumn extends TextColumn
{
    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.portal.order-status-column';

    /**
     * Get the order type.
     *
     * @return string
     */
    public function getOrderType(): string
    {
        return $this->record->getType();
    }

    /**
     * Get the formatted order type.
     *
     * @return string
     */
    public function getOrderTypeFormatted(): string
    {
        return __(sprintf('orders.type.%s', $this->record->getType()));
    }

    /**
     * Get the formatted order status.
     *
     * @return string
     */
    public function getOrderStatusFormatted(): string
    {
        return __(sprintf('orders.status.%s', $this->record->getStatus()));
    }

    /**
     * Get the order status.
     *
     * @return string
     */
    public function getOrderStatus(): string
    {
        return $this->record->getStatus();
    }
}

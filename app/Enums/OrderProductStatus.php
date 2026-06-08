<?php

namespace App\Enums;

use App\Models\OrderProduct;

enum OrderProductStatus: string
{
    // Purchase order is in draft status
    case Initial = 'initial';

    // MTO-specific
    case Assembled = 'assembled';
    case Fitting = 'fitting';
    case Quote = 'quote';
    case Ordered = 'ordered';
    case Purchased = 'purchased';
    case Sent = 'sent'; // Release order: called off
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    // MTS-specific
    case InStock = 'in_stock';
    case BackOrder = 'back_order';
    // Common
    case Canceled = 'canceled';
    case PickedStock = 'picked_stock';
    case PickedReceived = 'picked_received';
    case AddToStock = 'add_to_stock';

    public function getLabel(): ?string
    {
        return match ($this) {
            // MTO-specific
            self::Initial => 'Selecteer',
            self::Fitting => 'Passing',
            self::Assembled => 'Montage',
            self::Quote => 'Offerte',
            self::Ordered => 'Order',
            self::Purchased => 'Ingekocht',
            self::Sent => 'Afgeroepen',
            self::Confirmed => 'Bevestigd',
            self::Canceled => 'Geannuleerd',
            self::Delivered => 'Geleverd',
            // MTS-specific
            self::InStock => 'Voorraad',
            self::BackOrder => 'Back-order',
            // Common
            self::PickedStock => 'Gepickt (voorraad)',
            self::PickedReceived => 'Gepickt (ingekocht)',
            self::AddToStock => 'Opboeken voorraad',

            default => null,
        };
    }

    /**
     * Main order phase (Main.order_status) for this product process tab.
     */
    public function getOrderStatus(): OrderStatus
    {
        return match ($this) {
            self::Fitting => OrderStatus::Fitting,
            self::Quote => OrderStatus::Quote,
            self::Ordered => OrderStatus::Order,
            self::Purchased => OrderStatus::Purchase,
            self::Assembled => OrderStatus::Assembly,
            self::Delivered => OrderStatus::Delivered,
            default => throw new \InvalidArgumentException(
                "OrderProductStatus::{$this->value} heeft geen verkoopproces-fase"
            ),
        };
    }

    /**
     * Map (sub)order status to the corresponding ViewOrder tab key.
     */
    public static function OrderStatusToTab(OrderStatus $status): string
    {
        $mainStatus = OrderStatus::getMainStatusFor($status);

        return match ($mainStatus) {
            OrderStatus::Fitting => 'fitting',
            OrderStatus::Quote,
            OrderStatus::Order => 'order',
            OrderStatus::Purchase => 'purchase',
            OrderStatus::Assembly => 'assembly',
            OrderStatus::Delivery => 'delivery',
            default => 'order',
        };
    }

    public static function getMtoStatuses(): array
    {
        return [
            self::Purchased,
            self::Confirmed,
            self::Delivered,
            self::PickedReceived,
        ];
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }


    public static function getMtoLabels(): array
    {
        $statuses = self::getMtoStatuses();
        return array_reduce($statuses, function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    public static function getInitialMtoStatuses(): array
    {
        return [
            self::Initial,
            self::PickedStock,
        ];
    }


    public static function getPickedMtoStatuses(): array
    {
        return [
            self::PickedStock,
            self::PickedReceived,
        ];
    }
    public static function getMtsStatuses(): array
    {
        return [
            self::InStock,
            self::BackOrder,
            self::PickedReceived,
        ];
    }

    public static function getMtsLabels(): array
    {
        $statuses = self::getMtsStatuses();
        return array_reduce($statuses, function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    public static function getStockOrderStatuses(): array
    {
        return [
            self::Purchased,
            self::Confirmed,
            self::Delivered,
            self::PickedReceived,
        ];
    }

    /**
     * Initial statuses for the purchase tab when no purchase order is linked to the main.
     */
    public static function getInitialStatuses(): array
    {
        return self::getInitialMtoStatuses();
    }

    public static function getInitialStatusLabels(): array
    {
        $statuses = self::getInitialStatuses();
        return array_reduce($statuses, function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    public static function getPickedStatusLabels(): array
    {
        $statuses = self::getPickedMtoStatuses();
        return array_reduce($statuses, function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    public static function getStockOrderLabels(): array
    {
        $statuses = self::getStockOrderStatuses();
        return array_reduce($statuses, function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    /**
     * Release order: same {@see self} values as elsewhere, different labels in the UI.
     *
     * @return list<self>
     */
    public static function getReleaseOrderLineStatuses(): array
    {
        return [
            self::Sent,
            self::Confirmed,
            self::Delivered,
            self::PickedReceived,
        ];
    }

    /**
     * @return array<string, string> value => label for SelectColumn on release order screens
     */
    public static function getReleaseOrderLineStatusLabels(): array
    {
        return [
            self::Sent->value => 'Afgeroepen',
            self::Confirmed->value => 'Bevestigd',
            self::Delivered->value => 'Geleverd',
            self::PickedReceived->value => 'Gepickt (afroep)',
        ];
    }

    /**
     * @return array<string, string> value => label (Canceled tab on purchase only)
     */
    public static function getCanceledTabStatusLabels(): array
    {
        return [
            self::Canceled->value => self::Canceled->getLabel(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getCanceledTabStatusLabelsForRecord(?OrderProduct $record): array
    {
        $labels = self::getCanceledTabStatusLabels();

        if ($record !== null && $record->hasBeenInPurchaseProcess()) {
            $labels[self::AddToStock->value] = self::AddToStock->getLabel();
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public static function getCanceledTabBookedToStockStatusLabels(): array
    {
        return [
            self::AddToStock->value => self::AddToStock->getLabel(),
        ];
    }

    /**
     * Statuses where a line can no longer be canceled.
     *
     * @return list<self>
     */
    public static function getNonCancelableStatuses(): array
    {
        return [
            self::Ordered,
            self::Purchased,
            self::Sent,
            self::Confirmed,
            self::Delivered,
        ];
    }

    public function isCancelable(): bool
    {
        return ! in_array($this, self::getNonCancelableStatuses(), true);
    }
}

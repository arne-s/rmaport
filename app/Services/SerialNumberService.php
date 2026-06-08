<?php

namespace App\Services;

use App\Enums\OrderSubtype;
use App\Exceptions\SerialNumberAlreadyInUseException;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\SerialNumber;
use Illuminate\Support\Facades\DB;

class SerialNumberService
{
    /**
     * Validates that the serial number can be used for this order.
     *
     * @throws SerialNumberAlreadyInUseException When the serial number is already linked to another order.
     */
    public function validateSerialNumberForOrder(Order $order, string $serialNumberValue): void
    {
        $serialNumberValue = trim($serialNumberValue);

        if ($serialNumberValue === '') {
            return;
        }

        $serialNumber = SerialNumber::query()
            ->where('serial_number', $serialNumberValue)
            ->where('order_sub_type', OrderSubtype::Unit->value)
            ->first();

        if ($serialNumber === null) {
            return;
        }

        if ($serialNumber->getOrderId() !== null && $serialNumber->getOrderId() !== $order->getId()) {
            $existingOrder = Order::query()->find($serialNumber->getOrderId());
            if ($existingOrder !== null) {
                // Allow moving the serial number between revisions within the same main (request).
                if ($order->main_id !== null && $existingOrder->main_id === $order->main_id) {
                    return;
                }

                throw new SerialNumberAlreadyInUseException($serialNumber, $existingOrder);
            }
        }
    }

    /**
     * Sync the serial number to an order.
     * Old serial number linked to this order is removed and replaced.
     *
     * @return SerialNumber|null The resolved SerialNumber, or null when the value was empty.
     * @throws SerialNumberAlreadyInUseException
     */
    public function syncFromOrder(Order $order, string $serialNumberValue): ?SerialNumber
    {
        $serialNumberValue = trim($serialNumberValue);

        return DB::transaction(function () use ($order, $serialNumberValue): ?SerialNumber {
            SerialNumber::query()
                ->where('order_id', $order->getId())
                ->where('order_sub_type', OrderSubtype::Unit->value)
                ->delete();

            if ($serialNumberValue === '') {
                return null;
            }

            $this->validateSerialNumberForOrder($order, $serialNumberValue);

            $serialNumber = SerialNumber::query()->firstOrNew([
                'serial_number' => $serialNumberValue,
                'order_sub_type' => OrderSubtype::Unit->value,
            ]);
            $frameProduct = $order->frameProduct ?? $order->main?->frameProduct;

            $chairColorRaw = data_get($order->main?->getAdditional(), 'chair_color')
                ?: data_get($frameProduct?->additional, 'chair_color');

            $serialNumber->setOrderSubType(OrderSubtype::Unit);
            $serialNumber->setOrderId($order->getId());
            $serialNumber->setMainId($order->main_id);
            $serialNumber->setOwnerId($order->getCustomerId());
            $serialNumber->setName($frameProduct?->getName());
            $serialNumber->setType($frameProduct?->getChairType());
            $serialNumber->setColor(is_string($chairColorRaw) && $chairColorRaw !== '' ? $chairColorRaw : null);
            $serialNumber->setOrderDate($order->created_at);
            $serialNumber->setOrderNumber($order->getUid());
            $serialNumber->setTotalPriceInc($order->getCompanySalesPriceTotalIncVat());

            $customer = $order->customer;
            $serialNumber->setCustomerName($customer?->getName());
            $serialNumber->setCustomerDebtorNumber($customer?->debtor_number);
            $serialNumber->setDeliveredAt(null);

            $serialNumber->save();

            return $serialNumber;
        });
    }

    /**
     * Persist {@see SerialNumber} against {@see Main::getLastOrder()}. When there is no last order yet, does nothing.
     *
     * @return SerialNumber|null Resolved row, or null when empty or no last order.
     * @throws SerialNumberAlreadyInUseException
     */
    public function syncFromMainLastOrder(Main $main, string $serialNumberValue): ?SerialNumber
    {
        $lastOrder = $main->getLastOrder();
        if ($lastOrder === null) {
            return null;
        }

        return $this->syncFromOrder($lastOrder, $serialNumberValue);
    }

    /**
     * After a new order revision is saved, move the serial_numbers row from the previous revision to the new one.
     */
    public function reattachSerialNumberToNewOrderRevision(Order $previousOrder, Order $newOrder): void
    {
        if ($previousOrder->getId() === $newOrder->getId()) {
            return;
        }

        $previousMainId = $previousOrder->main_id;
        $newMainId = $newOrder->main_id;
        if ($previousMainId === null || $newMainId === null || (int) $previousMainId !== (int) $newMainId) {
            return;
        }

        DB::transaction(function () use ($previousOrder, $newOrder): void {
            $serialNumber = SerialNumber::query()
                ->where('order_id', $previousOrder->getId())
                ->where('order_sub_type', OrderSubtype::Unit->value)
                ->first();

            if ($serialNumber === null) {
                return;
            }

            SerialNumber::query()
                ->where('order_id', $newOrder->getId())
                ->where('order_sub_type', OrderSubtype::Unit->value)
                ->delete();

            $frameProduct = $newOrder->frameProduct ?? $newOrder->main?->frameProduct;

            $chairColorRaw = data_get($newOrder->main?->getAdditional(), 'chair_color')
                ?: data_get($frameProduct?->additional, 'chair_color');

            $serialNumber->setOrderSubType(OrderSubtype::Unit);
            $serialNumber->setOrderId($newOrder->getId());
            $serialNumber->setMainId($newOrder->main_id);
            $serialNumber->setOwnerId($newOrder->getCustomerId());
            $serialNumber->setName($frameProduct?->getName());
            $serialNumber->setType($frameProduct?->getChairType());
            $serialNumber->setColor(is_string($chairColorRaw) && $chairColorRaw !== '' ? $chairColorRaw : null);
            $serialNumber->setOrderDate($newOrder->created_at);
            $serialNumber->setOrderNumber($newOrder->getUid());
            $serialNumber->setTotalPriceInc($newOrder->getCompanySalesPriceTotalIncVat());

            $customer = $newOrder->customer;
            $serialNumber->setCustomerName($customer?->getName());
            $serialNumber->setCustomerDebtorNumber($customer?->debtor_number);
            $serialNumber->setDeliveredAt(null);

            $serialNumber->save();
        });
    }

    /**
     * When the aanvraag (main) reaches fully delivered, stamp the linked serial row once.
     */
    public function markDeliveredAtForMain(Main $main): void
    {
        $lastOrder = $main->getLastOrder();
        if ($lastOrder === null) {
            return;
        }

        SerialNumber::query()
            ->where('order_id', $lastOrder->getId())
            ->where('order_sub_type', OrderSubtype::Unit->value)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);
    }
}

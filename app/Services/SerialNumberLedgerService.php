<?php

namespace App\Services;

use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\SerialNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SerialNumberLedgerService
{
    public function recordCompletedFollowUpMain(Main $main): void
    {
        $subtype = $main->getSubtype();
        if (! in_array($subtype, [OrderSubtype::Part, OrderSubtype::Service], true)) {
            return;
        }

        $serialNumberValue = trim((string) data_get($main->getFittingNote(), 'linked_serial_number', ''));
        if ($serialNumberValue === '') {
            return;
        }

        $lastOrder = $main->getLastOrder();
        if (! $lastOrder instanceof Order) {
            return;
        }

        DB::transaction(function () use ($main, $subtype, $serialNumberValue, $lastOrder): void {
            $unitRow = $this->resolveUnitRow($serialNumberValue, $main);

            $ledgerRow = SerialNumber::query()->firstOrNew([
                'main_id' => $main->getId(),
            ]);

            $ledgerRow->setSerialNumber($serialNumberValue);
            $ledgerRow->setOrderSubType($subtype);
            $ledgerRow->setOrderId($lastOrder->getId());
            $ledgerRow->setOwnerId($main->getCustomerId());
            $ledgerRow->setOrderDate($lastOrder->created_at);
            $ledgerRow->setOrderNumber($lastOrder->getUid());
            $ledgerRow->setTotalPriceInc($lastOrder->getCompanySalesPriceTotalIncVat());

            $customer = $main->customer;
            $ledgerRow->setCustomerName($customer?->getName());
            $ledgerRow->setCustomerDebtorNumber($customer?->debtor_number);

            if ($subtype === OrderSubtype::Part && $main->getOrderStatus() === \App\Enums\OrderStatus::Delivered) {
                $ledgerRow->setDeliveredAt(now());
            }

            $ledgerRow->save();

            $this->recordCompletionEventIfNeeded($unitRow, $main, $subtype, $ledgerRow);
        });
    }

    private function resolveUnitRow(string $serialNumberValue, Main $followUpMain): SerialNumber
    {
        $existingUnit = SerialNumber::query()
            ->where('serial_number', $serialNumberValue)
            ->where('order_sub_type', OrderSubtype::Unit->value)
            ->first();

        if ($existingUnit !== null) {
            return $existingUnit;
        }

        $legacyRow = SerialNumber::query()
            ->where('serial_number', $serialNumberValue)
            ->first();

        if ($legacyRow !== null) {
            $legacyRow->setOrderSubType(OrderSubtype::Unit);
            $legacyRow->save();

            return $legacyRow;
        }

        $customer = $followUpMain->customer;

        $unitRow = new SerialNumber();
        $unitRow->setSerialNumber($serialNumberValue);
        $unitRow->setOrderSubType(OrderSubtype::Unit);
        $unitRow->setOwnerId($followUpMain->getCustomerId());
        $unitRow->setCustomerName($customer?->getName());
        $unitRow->setCustomerDebtorNumber($customer?->debtor_number);
        $unitRow->save();

        return $unitRow;
    }

    private function recordCompletionEventIfNeeded(
        SerialNumber $unitRow,
        Main $main,
        OrderSubtype $subtype,
        SerialNumber $ledgerRow,
    ): void {
        $mainId = $main->getId();

        $alreadyRecorded = $unitRow->serialNumberEvents()
            ->where('data->ledger_main_id', $mainId)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $label = $subtype->getLabel() ?? $subtype->value;
        $uid = $main->getUid() ?? (string) $mainId;
        $amount = $ledgerRow->getTotalPriceInc();
        $amountFormatted = $amount !== null ? ' (€ ' . format_money_amount($amount) . ')' : '';

        $unitRow->serialNumberEvents()->create([
            'type' => "{$label} {$uid} afgerond{$amountFormatted}",
            'data' => [
                'ledger_main_id' => $mainId,
                'order_sub_type' => $subtype->value,
                'ledger_serial_number_id' => $ledgerRow->getId(),
            ],
            'user_id' => Auth::id(),
        ]);
    }
}

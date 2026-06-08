<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Filament\Resources\Pages\Page;

class CreateOrderFromMain extends Page
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Verkooporder';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $main): void
    {
        $mainOrder = Main::withoutGlobalScopes()->find($main);
        if ($mainOrder === null) {
            $this->redirect(route('filament.app.resources.orders.index'), navigate: true);

            return;
        }

        $existingOrderDraft = Order::withoutGlobalScopes()
            ->where('main_id', $mainOrder->getId())
            ->where('type', 'order')
            ->where('status', OrderGeneralStatus::Draft)
            ->orderByDesc('id')
            ->first();

        if ($existingOrderDraft !== null) {
            $this->redirect(route('filament.app.resources.orders.edit', ['record' => $existingOrderDraft->id], true), navigate: true);

            return;
        }

        $orderDraft = $this->createOrderDraftFromMain($mainOrder);

        $this->redirect(route('filament.app.resources.orders.edit', ['record' => $orderDraft->id], true), navigate: true);
    }

    protected function createOrderDraftFromMain(Main $main): Order
    {
        $additional = [];
        $conditionCode = $main->getExactPaymentConditionInheritedByChildren();
        if ($conditionCode !== '') {
            $additional['exact_payment_condition'] = $conditionCode;
        }

        $order = Order::withoutGlobalScopes()->create([
            'type'                  => 'order',
            'main_id'               => $main->getId(),
            'customer_id'           => $main->getCustomerId(),
            'billing_customer_id'   => $main->billing_customer_id,
            'shipping_customer_id'  => $main->shipping_customer_id ?? $main->billing_customer_id,
            'customer_address_type' => $main->getCustomerAddressType(),
            'reference'             => $main->getUid(),
            'subtype'               => $main->getSubtype()?->value,
            'advisor_id'            => $main?->getAdvisorId(),
            'status'                => OrderGeneralStatus::Initial,
            'order_status'          => OrderStatus::Order,
            'payment_terms'         => $main->getPaymentTermsInheritedByChildren(),
            'additional'            => $additional ?: null,
        ]);

        $order->save();

        return $order;
    }
}

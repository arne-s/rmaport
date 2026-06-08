<?php

namespace App\Filament\Resources\ProductionResource\Pages;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\ProductionResource;
use App\Models\Order\Order;
use App\Services\InventoryService;
use App\Services\ProductionOverviewQueries;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;

class ListProduction extends ListRecords
{
    protected static string $resource = ProductionResource::class;
    protected static ?string $breadcrumb = 'Proces';

    public ?string $status = null;
    public ?Order $confirmOrder = null;


    protected function getTableQueryForOrderProductStatus(OrderProductStatus $status): Builder
    {
        $values = OrderStatus::orderStatusColumnValuesForPhase($status->getOrderStatus());

        return ProductionResource::getEloquentQuery()
            ->whereIn('order_status', $values);
    }

    protected function getTableQueryOrdered(): Builder
    {
        return ProductionOverviewQueries::ordered();
    }

    protected function getTableQueryAssembled(): Builder
    {
        return ProductionOverviewQueries::assembled();
    }

    protected function getTableQueryFitting(): Builder
    {
        return ProductionOverviewQueries::fitting();
    }

    protected function getTableQueryQuote(): Builder
    {
        return ProductionOverviewQueries::quote();
    }

    protected function getTableQueryProcessed(): Builder
    {
        $ordered = OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Order);

        return ProductionResource::getEloquentQuery()
            ->whereIn('order_status', $ordered)
            ->where('type', 'order');
    }

    protected function getTableQueryDelivered(): Builder
    {
        return ProductionOverviewQueries::delivered();
    }

    protected function getTableQueryReadyForPickup(): Builder
    {
        return ProductionResource::getEloquentQuery()
            ->whereIn('order_status', [OrderStatus::ReadyForPickup->value])
            ->where('type', 'order');
    }

    //    protected function getTableQueryCollected(): Builder
    //    {
    //        return ProductionResource::getEloquentQuery()
    //            ->whereIn('order_status', [OrderStatus::Collected])
    //            ->where('type', 'order');
    //    }

    protected function getHeaderActions(): array
    {
        $arr = [];

        foreach (
            [
                OrderProductStatus::Fitting,
                OrderProductStatus::Quote,
                OrderProductStatus::Ordered,
                OrderProductStatus::Assembled,
                OrderStatus::Delivered,
            ] as $i => $status
        ) {


            $count = 0;

            $label = $status->getLabel();

            if ($status->value === 'fitting') {
                $count = $this->getTableQueryFitting();
            }
            if ($status->value === 'quote') {
                $count = $this->getTableQueryQuote();
            }
            if ($status->value === 'ordered') {
                $count = $this->getTableQueryOrdered();
            }
            if ($status->value === 'assembled') {
                $count = $this->getTableQueryAssembled();
            }
            if ($status->value === 'delivered') {
                $label = 'Levering/Verzending';
                $count = $this->getTableQueryDelivered();
            }

            $nr = $i + 1;

            $arr[] = Action::make('btn' . $i + 1)
                ->url(
                    request()->routeIs('filament.app.resources.production.' . $status->value)
                        ? route('filament.app.resources.production.index')
                        : route('filament.app.resources.production.' . $status->value)
                )
                ->extraAttributes(fn() => [
                    'class' => $this->status === $status->value ? 'tab-button-blue' : 'tab-button-white'
                ])
                ->label(fn() => $nr . '. ' . $label . ' (' . $count->clone()->count() . ')');
        }
        return $arr;
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.app.resources.production.index') => 'Verkoopproces',
        ];
    }


    // Handle status change
    public function handleStatusChange(Order $record, OrderStatus|string $state)
    {
        if (empty($state) || empty($record)) {
            return;
        }

        if ($state === OrderStatus::ReadyForPickup->value) {
            $this->confirmOrder = $record;

            // Show confirmation modal when setting Order to Ready for Pickup§§
            $this->dispatch('open-modal', id: 'order_picked_confirm');
        } else {
            $newStatus = OrderStatus::tryFrom($state);
            if ($newStatus !== null) {
                $record->setOrderStatus($newStatus);
                $record->save();
                $record->refresh();
                Notification::make()
                    ->title('Status bijgewerkt naar ' . ($newStatus->getLabel() ?? $newStatus->value))
                    ->success()
                    ->send();
            }
        }
    }

    #[On('confirmOrderPicked')]
    public function confirmOrderPicked(bool $confirm): void
    {
        if ($confirm) {
            $order = $this->confirmOrder;

            // Reduce physical and reserved stock when picking a Make-to-Stock product
            $orderProducts = $order->orderProducts()->get();
            foreach ($orderProducts as $orderProduct) {
                if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToStock) {
                    $inventoryService = app(InventoryService::class);
                    $inventoryService->pickOrderProduct($orderProduct);
                }

                // Update order product status
                $orderProduct->setStatus(OrderProductStatus::PickedStock);
                $orderProduct->save();
            }

            // Set order status to Ready for Pickup/Invoiced.
            $order->setOrderStatus(OrderStatus::ReadyForPickup);
            $order->save();

            Notification::make()
                ->title("De orderstatus is bijgewerkt en de order zal binnen een minuut gefactureerd worden.")
                ->success()
                ->send();
        }
        $this->confirmOrder = null;
        $this->dispatch('close-modal', id: 'order_picked_confirm');
    }

    public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                        'class' => 'mt-[-67px] breadcrumb-mob-production',
                    ]),

                ...$schema->getComponents(),
                view('livewire.modals.order-picked-confirm-modal'),
            ]);
    }
}

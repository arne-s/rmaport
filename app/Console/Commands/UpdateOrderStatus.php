<?php
namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Order\BaseOrder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class UpdateOrderStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update-order-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the status of orders.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        /** @var Collection<int, BaseOrder> */
        $orders = BaseOrder::whereIn('type', ['order', 'stock_order'])
            ->whereIn('order_status', [
                OrderStatus::PoConfirmed,
                OrderStatus::PartiallyReceived,
                OrderStatus::Delivered,
            ])
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->whereNot('is_cancelled', true)
            ->get();

        foreach ($orders as $order) {
            // If all purchase orders are delivered, set the order status to delivered
            if (
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Delivered)->exists() &&
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Delivered)->count() === $order->purchaseOrders()->count()
            ) {
                $this->updateOrderStatus($order, OrderStatus::Delivered);
            }
            // If any purchase order is partially delivered, set the order status to partially delivered
            elseif ($order->purchaseOrders()->where('status', PurchaseOrderStatus::PartiallyDelivered)->exists()) {
                $this->updateOrderStatus($order, OrderStatus::PartiallyReceived);
            }
            // If some but not all purchase orders are delivered, set the order status to partially delivered
            elseif (
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Delivered)->exists() &&
                $order->purchaseOrders()->where('status', '!=', PurchaseOrderStatus::Delivered)->exists()
            ) {
                $this->updateOrderStatus($order, OrderStatus::PartiallyReceived);
            }
            // If all purchase orders are confirmed, set the order status to confirmed
            elseif (
                !in_array($order->getOrderStatus(), [
                    OrderStatus::PartiallyReceived,
                    OrderStatus::Delivered,
                ], true) &&
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Confirmed)->exists() &&
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Confirmed)->count() === $order->purchaseOrders()->count()
            ) {
                $this->updateOrderStatus($order, OrderStatus::PoConfirmed);
            }
            // If some but not all purchase orders are confirmed, set the order status to partially confirmed
            elseif (
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Confirmed)->exists() &&
                $order->purchaseOrders()->where('status', '!=', PurchaseOrderStatus::Confirmed)->exists()
            ) {
                $this->updateOrderStatus($order, OrderStatus::PartiallyConfirmed);
            }
            // If all purchase orders are ordered, set the order status to verified
            elseif (
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Purchased)->exists() &&
                $order->purchaseOrders()->where('status', PurchaseOrderStatus::Purchased)->count() === $order->purchaseOrders()->count()
            ) {
                $this->updateOrderStatus($order, OrderStatus::PartiallyConfirmed);
            }
        }

        $this->info('UpdateOrderStatus has been ran.');
        return 0;
    }

    public function updateOrderStatus(BaseOrder $order, OrderStatus $status): bool
    {
        if ($order->order_status === $status) return false;

        $order->order_status = $status; // Set the new status
        $order->save(); // Trigger the updated event

        $this->log("Order {$order->uid} status updated to '{$status->value}'");
        return true;
    }

    public function log(string $message, string $level = 'info'): void
    {
        $message = "[Commands\UpdateOrderStatus] $message";
        Log::$level($message);
        $this->info($message);
    }
}

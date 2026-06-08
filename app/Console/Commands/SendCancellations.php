<?php
namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class SendCancellations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:send-cancellations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send cancellation emails for cancelled orders, with credit invoice(s) if applicable.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        /** @var Collection<int, Order> */
        $orders = Order::where('is_cancelled', true)
            ->whereNotNull('uid')
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->whereNull('cancelled_at')
            ->get();

        foreach ($orders as $order) {
            try {
                $success = $order->sendCancellation();
                if (!$success) continue;

                $this->info("Cancellation email for order {$order->uid} has been sent.");
            } catch (Throwable $e) {
                $this->error("Failed to send cancellation email for order {$order->uid}: {$e}");
                report($e);
            }
        }

        $this->info('Cancellation emails have been sent.');
        return 0;
    }
}

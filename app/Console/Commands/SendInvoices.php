<?php
namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use App\Models\Order\Order;
use Illuminate\Console\Command;
use Throwable;

class SendInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:send-invoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create and send invoices when the order is ready for pickup (all items have been delivered and picked).';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $mains = Main::where('order_status', OrderStatus::ReadyForPickup->value)
            ->where('subtype', '!=', OrderSubtype::Part->value)
            ->with([
                'billingCustomer',
                'orders' => function ($query): void {
                    $query->whereNull('invoice_id')
                        ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value, OrderGeneralStatus::Completed->value]);
                },
            ])
            ->get();

        foreach ($mains as $main) {
            if ($main->shouldSuppressAutomaticInvoicing()) {
                continue;
            }

            /** @var Order $order */
            foreach ($main->orders as $order) {
                try {
                    $invoice = $order->createInvoice();
                    $this->info("Invoice for order {$order->uid} (main {$main->id}) created: {$invoice->getId()}");
                } catch (Throwable $e) {
                    $this->error("Failed to create invoice for order {$order->uid} (main {$main->id}): {$e->getMessage()}");
                }
            }
        }

        $this->info('Done.');
        return 0;
    }
}

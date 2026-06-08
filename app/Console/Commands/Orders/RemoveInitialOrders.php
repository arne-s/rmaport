<?php

namespace App\Console\Commands\Orders;

use App\Enums\OrderGeneralStatus;
use App\Models\Document;
use App\Models\Order\BaseOrder;
use App\Models\OrderEvent;
use App\Models\OrderProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RemoveInitialOrders extends Command
{
    protected $signature = 'orders:remove-initial {--dry-run : Count only; do not delete}';

    protected $description = 'Deletes all orders (any type) with status initial that are older than 7 days.';

    public function handle(): int
    {
        $cutoff = now()->subWeek();

        $query = BaseOrder::query()
            ->withoutGlobalScopes()
            ->where('status', OrderGeneralStatus::Initial->value)
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No initial orders older than 7 days.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: {$count} row(s) would be deleted.");

            return self::SUCCESS;
        }

        $deleted = 0;
        $query->orderBy('id')->chunkById(50, function ($orders) use (&$deleted): void {
            foreach ($orders as $order) {
                try {
                    DB::transaction(function () use ($order): void {
                        $id = $order->getId();

                        OrderProduct::query()->where('order_id', $id)->delete();
                        OrderEvent::query()->where('order_id', $id)->delete();

                        Document::query()
                            ->where('documentable_type', $order->getMorphClass())
                            ->where('documentable_id', $id)
                            ->delete();

                        if (method_exists($order, 'media')) {
                            foreach ($order->media as $media) {
                                $media->delete();
                            }
                        }

                        DB::table('orders')->where('id', $id)->delete();
                    });
                    $deleted++;
                } catch (Throwable $e) {
                    report($e);
                    $this->error('Delete failed for order id '.$order->getId().': '.$e->getMessage());
                }
            }
        });

        $this->info("Deleted {$deleted} of {$count} initial order(s) older than 7 days.");

        return self::SUCCESS;
    }
}

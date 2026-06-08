<?php

namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Models\Order\BaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Benchmark;

class SaveInvoicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invoices:save {--disk=public : Storage disk to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate PDFs for invoice orders which have not been saved, save them to the public storage disk, and update doc_id and doc_path.';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: 'public';

        $this->info('Starting invoice export to disk: ' . $disk);

        BaseOrder::whereIn('type', ['invoice', 'deposit_invoice', 'credit_invoice'])
            ->whereNotNull('uid')
            ->whereNotNull('sent_at')
            ->whereNotIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->whereNull('doc_path')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($disk) {
                foreach ($orders as $order) {
                    try {
                        $path = $order->saveDocToStorage($disk);

                        if ($path) {
                            $this->info("Saved order {$order->id} -> {$path}");
                        } else {
                            $this->warn("Order {$order->id} could not be saved.");
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        $this->error("Failed order {$order->id}: " . $e->getMessage());
                        continue;
                    }
                }
            });

        $this->info('Finished.');

        return 0;
    }
}

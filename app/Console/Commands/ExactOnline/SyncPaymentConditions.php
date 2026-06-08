<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncPaymentConditions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:sync-payment-conditions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync payment conditions from Exact Online';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $service = new ExactOnlineService();

        if ($service->syncPaymentConditions()) {
            $this->info('Payment conditions synced successfully.');
            return 0;
        }

        $this->error('Failed to sync payment conditions. Check the exact-online log for details.');
        return 1;
    }
}
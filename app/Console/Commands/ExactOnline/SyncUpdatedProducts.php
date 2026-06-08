<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncUpdatedProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:sync-updated-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update products in Exact Online';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $service = new ExactOnlineService();
        return $service->syncUpdatedProducts() ? 0 : 1;
    }
}

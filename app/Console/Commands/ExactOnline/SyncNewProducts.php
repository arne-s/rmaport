<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncNewProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:sync-new-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create products that do not exist in Exact Online';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $service = new ExactOnlineService();
        return $service->syncNewProducts() ? 0 : 1;
    }
}

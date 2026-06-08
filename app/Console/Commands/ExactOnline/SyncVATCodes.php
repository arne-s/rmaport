<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncVATCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:sync-vat-codes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync VAT codes from Exact Online';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $service = new ExactOnlineService();
        return $service->syncVATCodes() ? 0 : 1;
    }
}
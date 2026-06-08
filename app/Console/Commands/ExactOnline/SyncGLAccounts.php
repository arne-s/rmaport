<?php

namespace App\Console\Commands\ExactOnline;

use App\Models\ExactGLAccount;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncGLAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:sync-gl-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync GL accounts from Exact Online';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $service = new ExactOnlineService();
        return $service->syncGLAccounts() ? 0 : 1;
    }
}
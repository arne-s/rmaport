<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class SyncArticleGroups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exact-online:sync-article-groups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync article groups with Exact Online';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $service = new ExactOnlineService();
        return $service->syncArticleGroups() ? 0 : 1;
    }
}

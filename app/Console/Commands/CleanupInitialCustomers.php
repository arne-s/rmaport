<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupInitialCustomers extends Command
{
    const CLEAN_UP_AFTER_HOURS = 48;

    protected $signature = 'customers:cleanup-initial';

    protected $description = 'Delete customers with initial status that are older than 48 hours.';

    public function handle(): int
    {
        $deleted = DB::table('customers')
            ->where('status', CustomerStatus::Initial->value)
            ->where('created_at', '<=', Carbon::now()->subHours(self::CLEAN_UP_AFTER_HOURS))
            ->delete();

        Log::info("Initial customers cleaned up. Deleted: {$deleted}");
        $this->info("Initial customers cleaned up. Deleted: {$deleted}");

        return 0;
    }
}

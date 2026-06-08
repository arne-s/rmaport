<?php
namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupDrafts extends Command
{
    const CLEAN_UP_AFTER_HOURS = 24;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cleanup-drafts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up drafts.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $deleted = DB::table('orders')
            ->whereIn('type', ['order', 'stock_order', 'credit_invoice'])
            ->whereIn('status', [OrderGeneralStatus::Initial->value, OrderGeneralStatus::Draft->value])
            ->where('created_at', '<=', Carbon::now()->subHours(self::CLEAN_UP_AFTER_HOURS))
            ->whereRaw('updated_at = created_at')
            ->delete();

        Log::info("Draft orders cleaned up. Deleted: {$deleted}");
        $this->info("Draft orders cleaned up. Deleted: {$deleted}");

        return 0; // success status code
    }
}

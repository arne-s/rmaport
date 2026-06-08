<?php
namespace App\Console\Commands;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Main;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireQuotes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:expire-quotes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the status of expired quotes to "expired".';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $query = DB::table('orders')
            ->where('type', 'quote')
            ->whereIn('status', [OrderGeneralStatus::Pending->value, OrderGeneralStatus::Sent->value])
            ->whereDate('expires_at', '<=', Carbon::today());

        $mainIds = (clone $query)->whereNotNull('main_id')->distinct()->pluck('main_id');

        $query->update(['status' => 'expired']);

        $quotePhaseStatuses = [
            OrderStatus::QuoteDraft,
            OrderStatus::QuoteConcept,
            OrderStatus::QuoteSent,
        ];

        foreach ($mainIds as $mainId) {
            $main = Main::find($mainId);
            if ($main !== null) {
                $currentStatus = $main->getOrderStatus();
                if ($currentStatus !== null && in_array($currentStatus, $quotePhaseStatuses, true)) {
                    $main->changeOrderStatus(OrderStatus::QuoteExpired);
                }
            }
        }

        Log::info('Expired quotes have been updated.');
        $this->info('Expired quotes have been updated.');

        return 0; // success status code
    }
}

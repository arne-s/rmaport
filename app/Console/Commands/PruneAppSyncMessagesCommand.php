<?php

namespace App\Console\Commands;

use App\Models\AppSyncMessage;
use Illuminate\Console\Command;

class PruneAppSyncMessagesCommand extends Command
{
    protected $signature = 'app:prune-app-sync-messages
                            {--days=30 : Verwijder verwerkte meldingen ouder dan dit aantal dagen}';

    protected $description = 'Verwijder oude, verwerkte app_sync_messages (polling-toasts voor o.a. Exact-sync)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);

        $deleted = AppSyncMessage::query()
            ->whereNotNull('consumed_at')
            ->where('consumed_at', '<', $threshold)
            ->delete();

        $this->info("Verwijderd: {$deleted} record(s) (consumed_at voor ". $threshold->toDateTimeString() . ').');

        return self::SUCCESS;
    }
}

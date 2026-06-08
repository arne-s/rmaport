<?php

namespace App\Console\Commands;

use App\Services\Reporting\RefreshMainReport;
use Illuminate\Console\Command;

class RefreshMainReportsCommand extends Command
{
    protected $signature = 'main-reports:refresh {--main-id= : Only refresh the Main with this id}';

    protected $description = 'Rebuild denormalized main_reports rows from live Main/order data.';

    public function handle(RefreshMainReport $refreshMainReport): int
    {
        $mainId = $this->option('main-id');
        $id = $mainId !== null && $mainId !== ''
            ? (int) $mainId
            : null;

        if ($id === 0) {
            $this->error('Invalid --main-id.');

            return self::FAILURE;
        }

        $this->info($id !== null
            ? "Refreshing main_reports for main_id {$id}…"
            : 'Refreshing main_reports for all mains…');

        $refreshMainReport->refresh($id);

        $this->info('Done.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\Exact\Suppliers\ExactSupplierImportService;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class ImportSuppliersFromExact extends Command
{
    protected $signature = 'exact-online:import-suppliers
                            {--no-progress : Do not show a progress bar}';

    protected $description = 'Import or update suppliers from Exact Online CRM Accounts (IsSupplier)';

    public function handle(ExactSupplierImportService $importService, ExactOnlineService $exact): int
    {
        if (! config('exact.enabled')) {
            $this->error('Exact Online is disabled (exact.enabled).');

            return self::FAILURE;
        }

        if (! $exact->ensureAccessTokenForApi()) {
            $this->error('Could not obtain Exact Online access token. Run exact-online:refresh-tokens or reconnect Exact in the admin.');

            return self::FAILURE;
        }

        $this->info('Starting Exact supplier import...');

        $showProgress = ! $this->option('no-progress');
        $progressBar = null;

        $stats = $importService->import(
            $showProgress ? function (int $total) use (&$progressBar): void {
                if ($total > 0) {
                    $progressBar = $this->output->createProgressBar($total);
                    $progressBar->start();
                }
            } : null,
            $showProgress ? function () use (&$progressBar): void {
                $progressBar?->advance();
            } : null,
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine();
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Failed', $stats['failed']],
            ]
        );

        if ($stats['processed'] === 0 && $stats['failed'] === 0) {
            $this->warn('No supplier accounts returned from Exact Online.');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

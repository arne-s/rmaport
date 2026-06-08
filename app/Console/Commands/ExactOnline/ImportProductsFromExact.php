<?php

namespace App\Console\Commands\ExactOnline;

use App\Services\Exact\Products\ExactProductImportService;
use Illuminate\Console\Command;

class ImportProductsFromExact extends Command
{
    protected $signature = 'exact-online:import-products
                            {--concurrency=10 : Max number of products to process per batch}
                            {--no-progress : Do not show a progress bar}
                            {--product-id=* : Limit import/sync to these local product IDs (must have exact_id)}';

    protected $description = 'Import or update products from Exact Online Items (match on exact_id only)';

    public function handle(ExactProductImportService $importService): int
    {
        $onlyProductIds = array_values(array_unique(array_filter(array_map('intval', (array) $this->option('product-id')))));

        $concurrency = (int) $this->option('concurrency');
        if ($concurrency < 1) {
            $this->error('concurrency must be at least 1');

            return self::INVALID;
        }

        $this->info('Starting Exact product import...');

        $showProgress = ! $this->option('no-progress');
        $progressBar = null;

        $stats = $importService->import(
            $concurrency,
            $showProgress ? function (int $total) use (&$progressBar): void {
                if ($total > 0) {
                    $progressBar = $this->output->createProgressBar($total);
                    $progressBar->start();
                }
            } : null,
            $showProgress ? function () use (&$progressBar): void {
                $progressBar?->advance();
            } : null,
            $onlyProductIds !== [] ? $onlyProductIds : null,
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

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

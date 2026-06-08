<?php

namespace App\Console\Commands\ExactOnline;

use App\Models\Customer;
use App\Services\Exact\Documents\ExactDocumentImportService;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class ImportDocumentsFromExact extends Command
{
    protected $signature = 'exact-online:import-documents
                            {--customer= : Import documents for a specific customer ID only}
                            {--limit= : Maximum number of customers to process}
                            {--no-progress : Do not show a progress bar}';

    protected $description = 'Import documents (PDFs) from Exact Online for all customers and companies.';

    public function handle(
        ExactDocumentImportService $importService,
        ExactOnlineService $exactOnline,
    ): int {
        if (! $exactOnline->ensureAccessTokenForApi()) {
            $this->error('Could not obtain Exact Online access token.');

            return self::FAILURE;
        }

        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = Customer::query()->whereNotNull('exact_id');

        if ($customerId !== null) {
            $query->where('id', $customerId);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $customers = $query->get();

        if ($customers->isEmpty()) {
            $this->warn('No customers with an Exact ID found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Processing %d customer(s)...', $customers->count()));

        $showProgress = ! $this->option('no-progress');
        $bar = $showProgress ? $this->output->createProgressBar($customers->count()) : null;
        $bar?->start();

        $totalImported = 0;
        $totalFailed = 0;

        foreach ($customers as $customer) {
            try {
                $count = $importService->importForCustomer($customer);
                $totalImported += $count;
            } catch (\Throwable $e) {
                $totalFailed++;
                $this->newLine();
                $this->warn(sprintf(
                    'Failed for customer %d (%s): %s',
                    $customer->id,
                    $customer->getName(),
                    $e->getMessage(),
                ));
            }

            $bar?->advance();
        }

        $bar?->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Customers processed', $customers->count()],
                ['Documents imported', $totalImported],
                ['Customers failed', $totalFailed],
            ]
        );

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

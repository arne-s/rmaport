<?php

namespace App\Console\Commands\ExactOnline;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Services\Exact\Accounts\ExactAccounts;
use App\Services\Exact\Customers\ExactCustomerImportService;
use App\Services\ExactOnlineService;
use Illuminate\Console\Command;

class ImportAccountsFromExact extends Command
{
    protected $signature = 'exact-online:import-accounts
                            {--limit= : Maximum number of accounts to import}
                            {--code= : Import only the account with this Exact Code (debiteur-/relatiecode)}
                            {--prune-deleted-from-exact : After import, delete local customers removed from Exact (full sync only: no --code or --limit)}
                            {--no-progress : Do not show a progress bar}';

    protected $description = 'Importeer Exact CRM-relaties als klanten (alleen-leveranciers worden overgeslagen; klant+leverancier oké; Status C/P/S; Blocked → Inactive).';

    public function handle(
        ExactAccounts $exactAccounts,
        ExactCustomerImportService $customerImport,
        ExactOnlineService $exactOnline,
    ): int {
        if (! $exactOnline->ensureAccessTokenForApi()) {
            $this->error('Could not obtain Exact Online access token.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $codeOption = $this->option('code');
        $code = is_string($codeOption) ? trim($codeOption) : '';
        $code = $code !== '' ? $code : null;

        $statusFilter = ExactAccounts::ACCOUNTS_STATUS_FILTER_CUSTOMER_IMPORT;

        if ($code !== null) {
            $this->info(sprintf('Fetching accounts from Exact Online (Code = %s)...', $code));

            $odataCodeFilter = sprintf("Code eq '%s'", $this->escapeODataString($code));
            $accounts = $exactAccounts->fetchAll(
                $odataCodeFilter,
                ExactAccounts::MAX_PAGE_SIZE,
                ExactAccounts::ACCOUNT_SELECT,
            );

            if ($accounts === []) {
                $this->line('Exact OData filter on Code returned no rows; scanning CRM accounts by status...');
                $accounts = $exactAccounts->fetchAccountsMatchingCode($code, $statusFilter);
            }

            if ($limit !== null) {
                $accounts = array_slice($accounts, 0, $limit);
            }
        } else {
            $this->info('Fetching accounts from Exact Online...');

            $apiTotal = $exactAccounts->countAccountsMatchingFilter($statusFilter);
            if ($apiTotal !== null) {
                $this->info(sprintf(
                    'Exact CRM API: %d CRM-account(s) voldoen aan Status-filter (Customer/Prospect/Suspect); alleen-leveranciers (IsSupplier + geen verkoop) worden daarna overgeslagen.',
                    $apiTotal,
                ));
            } else {
                $this->warn('Exact CRM API: totaal aantal kon niet worden gelezen ($inlinecount). Ga door met ophalen van pagina\'s.');
            }

            if ($limit !== null && $limit <= ExactAccounts::MAX_PAGE_SIZE) {
                $accounts = $exactAccounts->fetchPage($limit, 0, $statusFilter, ExactAccounts::ACCOUNT_SELECT);
            } else {
                $accounts = $exactAccounts->fetchAll(
                    $statusFilter,
                    ExactAccounts::MAX_PAGE_SIZE,
                    ExactAccounts::ACCOUNT_SELECT,
                );

                if ($limit !== null) {
                    $accounts = array_slice($accounts, 0, $limit);
                }
            }
        }

        if ($code !== null && $accounts === []) {
            $this->warn(sprintf(
                'No Exact CRM account found with Code %s in this division (check code spelling, leading zeros, and EXACT_DIVISION).',
                $code,
            ));

            return self::SUCCESS;
        }

        $skippedSupplierOnly = 0;
        $accountsToImport = [];
        foreach ($accounts as $account) {
            if (! is_array($account) || ! isset($account['ID']) || ! is_string($account['ID'])) {
                continue;
            }

            if (ExactAccounts::crmAccountRowIsSupplierOnly($account)) {
                $skippedSupplierOnly++;

                continue;
            }

            $accountsToImport[] = $account;
        }

        if ($skippedSupplierOnly > 0) {
            $this->line(sprintf('%d CRM-account(s) overgeslagen (alleen leverancier in Exact: IsSupplier en geen verkoop).', $skippedSupplierOnly));
        }

        $this->info(sprintf('Found %d CRM account(s) to import as customers.', count($accountsToImport)));

        $customerStats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0];

        $accountIdsToProcess = array_values(array_filter(
            array_column($accountsToImport, 'ID'),
            fn ($id) => is_string($id) && $id !== '',
        ));

        $contactMap = $accountIdsToProcess !== []
            ? $exactAccounts->fetchMainContactsForAccounts($accountIdsToProcess)
            : [];

        $bankAccountMap = $accountIdsToProcess !== []
            ? $exactAccounts->fetchBankAccountsForAccounts($accountIdsToProcess)
            : [];

        if ($accountsToImport !== []) {
            $this->info('Importing customers...');
            $customerStats = $customerImport->import(
                $accountsToImport,
                $contactMap,
                $bankAccountMap,
            );
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Customers'],
            [
                ['Processed', $customerStats['processed']],
                ['Created', $customerStats['created']],
                ['Updated', $customerStats['updated']],
                ['Failed', $customerStats['failed']],
            ]
        );

        $totalFailed = $customerStats['failed'];

        if ($this->option('prune-deleted-from-exact')) {
            $this->pruneCustomersDeletedFromExact($exactAccounts, $accounts, $code, $limit);
        }

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function escapeODataString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Remove {@see Customer} rows whose Exact GUID no longer exists, using this run's imported ID set plus a GET check.
     *
     * @param  list<array<string, mixed>>  $accounts
     */
    private function pruneCustomersDeletedFromExact(
        ExactAccounts $exactAccounts,
        array $accounts,
        ?string $code,
        ?int $limit,
    ): void {
        if ($code !== null || $limit !== null) {
            $this->warn('Skipping --prune-deleted-from-exact: only runs on a full import (omit --code and --limit).');

            return;
        }

        if ($accounts === []) {
            $this->warn('Skipping --prune-deleted-from-exact: no accounts were fetched from Exact.');

            return;
        }

        /** @var array<string, true> $remoteExactIds */
        $remoteExactIds = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $id = $account['ID'] ?? null;
            if (is_string($id) && $id !== '') {
                $remoteExactIds[$id] = true;
            }
        }

        $this->info('Checking for local customers removed from Exact (--prune-deleted-from-exact)...');

        $pruned = 0;
        $skippedOrders = 0;

        /** @var iterable<int, Customer> $candidates */
        $candidates = Customer::query()
            ->whereNotNull('exact_id')
            ->where('exact_id', '!=', '')
            ->where('status', '!=', CustomerStatus::Test->value)
            ->cursor();

        foreach ($candidates as $customer) {
            $exactId = $customer->exact_id;
            if ($exactId === null || $exactId === '') {
                continue;
            }

            if (isset($remoteExactIds[$exactId])) {
                continue;
            }

            if ($exactAccounts->exactCrmAccountExists($exactId)) {
                continue;
            }

            if ($this->customerReferencedOnOrders((int) $customer->id)) {
                $skippedOrders++;
                $this->warn(sprintf(
                    'Customer id %d (%s): no longer in Exact but still referenced on orders — not deleted.',
                    $customer->id,
                    $customer->getName() ?? '?',
                ));

                continue;
            }

            $customer->delete();
            $pruned++;
        }

        if ($pruned > 0) {
            $this->info(sprintf('Pruned %d local customer(s) that were deleted in Exact.', $pruned));
        } else {
            $this->line('Prune: no deletable customers (none missing from Exact with a confirmed GET).');
        }

        if ($skippedOrders > 0) {
            $this->warn(sprintf(
                'Prune: skipped %d customer(s) that were removed from Exact but still appear on orders.',
                $skippedOrders,
            ));
        }
    }

    private function customerReferencedOnOrders(int $customerId): bool
    {
        return Order::query()
            ->where(function ($q) use ($customerId): void {
                $q->where('customer_id', $customerId)
                    ->orWhere('billing_customer_id', $customerId)
                    ->orWhere('shipping_customer_id', $customerId);
            })
            ->exists();
    }
}

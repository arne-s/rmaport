<?php

namespace App\Console\Commands\SerialNumbers;

use App\Models\Customer;
use App\Models\SerialNumber;
use Illuminate\Console\Command;

class LinkSerialNumberOwnersCommand extends Command
{
    protected $signature = 'serial-numbers:link-owners-from-debtor-numbers
                            {--dry-run : Toont wat er gekoppeld zou worden zonder op te slaan}';

    protected $description = 'Vult serial_numbers.owner_id voor historische rijen waar debiteurnummer exact één klant oplevert';

    /**
     * @var array<string, int>
     */
    private array $customerIdByDebtorKey = [];

    /**
     * @var array<string, true>
     */
    private array $ambiguousDebtorKeys = [];

    public function handle(): int
    {
        $this->buildDebtorResolutionMap();

        $dryRun = (bool) $this->option('dry-run');

        $linkedOrWould = 0;
        $skippedNoMatch = 0;
        $skippedAmbiguousDebtor = 0;

        SerialNumber::query()
            ->whereNull('owner_id')
            ->whereRaw("TRIM(COALESCE(customer_debtor_number, '')) <> ''")
            ->orderBy('id')
            ->chunkById(500, function ($serials) use ($dryRun, &$linkedOrWould, &$skippedNoMatch, &$skippedAmbiguousDebtor): void {
                foreach ($serials as $serial) {
                    if (! $serial instanceof SerialNumber) {
                        continue;
                    }

                    $key = trim((string) ($serial->customer_debtor_number ?? ''));

                    if ($key === '') {
                        continue;
                    }

                    if (isset($this->ambiguousDebtorKeys[$key])) {
                        $skippedAmbiguousDebtor++;

                        continue;
                    }

                    $customerId = $this->customerIdByDebtorKey[$key] ?? null;

                    if ($customerId === null) {
                        $skippedNoMatch++;

                        continue;
                    }

                    $linkedOrWould++;

                    if ($dryRun) {
                        continue;
                    }

                    $serial->owner_id = $customerId;
                    $serial->save();
                }
            });

        $ambiguousDebtorCount = count($this->ambiguousDebtorKeys);

        if ($ambiguousDebtorCount > 0) {
            $this->comment(
                "{$ambiguousDebtorCount} debiteursleutel(s) hebben meerdere klanten in de database — die worden niet voor automatische koppeling gebruikt."
            );
        }

        if ($dryRun) {
            $this->info(
                "[dry-run] Zou nu koppelen: {$linkedOrWould} rij(en); "
                ."geen unieke klant: {$skippedNoMatch}; gedubbeld debiteurnummer (klanten): {$skippedAmbiguousDebtor} serienummers overslagen."
            );
        } else {
            $this->info(
                "Gekoppeld: {$linkedOrWould} rij(en); "
                ."geen unieke klant: {$skippedNoMatch}; gedubbeld debiteurnummer (klanten): {$skippedAmbiguousDebtor} serienummers overgeslagen."
            );
        }

        return self::SUCCESS;
    }

    /**
     * Eén klant per genormaliseerd debiteurnummer (trim); bij meerdere klanten met hetzelfde nummer wordt de sleutel gemarkeerd als ambigu.
     */
    private function buildDebtorResolutionMap(): void
    {
        /** @var array<string, list<int>> $bucket */
        $bucket = [];

        foreach (Customer::query()->cursor() as $customer) {
            if (! $customer instanceof Customer) {
                continue;
            }

            $raw = $customer->debtor_number;
            if ($raw === null || $raw === '') {
                continue;
            }

            $key = trim((string) $raw);
            if ($key === '') {
                continue;
            }

            $bucket[$key][] = $customer->id;
        }

        foreach ($bucket as $key => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) === 1) {
                $this->customerIdByDebtorKey[$key] = $ids[0];

                continue;
            }

            $this->ambiguousDebtorKeys[$key] = true;
        }
    }
}

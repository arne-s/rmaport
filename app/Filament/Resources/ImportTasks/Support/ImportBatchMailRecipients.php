<?php

namespace App\Filament\Resources\ImportTasks\Support;

use App\Filament\Support\EmailRecipientResolver;
use App\Models\Customer;
use App\Models\ImportBatch;

final class ImportBatchMailRecipients
{
    public static function resolveCustomer(ImportBatch $batch): ?Customer
    {
        $batch->loadMissing(['importRows.customer', 'importRows.source.customer']);

        $row = $batch->importRows->first();

        return $row?->customer ?? $row?->source?->customer;
    }

    /**
     * @return array<string, string>
     */
    public static function recipientOptions(ImportBatch $batch): array
    {
        $options = [];

        $customer = self::resolveCustomer($batch);

        if ($customer instanceof Customer && filled($customer->getEmail())) {
            $options['customer'] = $customer->getName().' <'.$customer->getEmail().'>';
        }

        return array_merge(
            EmailRecipientResolver::getRecipientOptions(),
            $options,
        );
    }

    /**
     * @return array<int, string>
     */
    public static function defaultToRecipients(ImportBatch $batch): array
    {
        $customer = self::resolveCustomer($batch);

        if ($customer instanceof Customer && filled($customer->getEmail())) {
            return ['customer'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function resolveEmails(ImportBatch $batch, array $keys): array
    {
        $emails = [];
        $customer = self::resolveCustomer($batch);

        foreach ($keys as $key) {
            if ($key === 'customer' && $customer instanceof Customer && filled($customer->getEmail())) {
                $emails[] = (string) $customer->getEmail();
            }
        }

        $otherKeys = array_values(array_filter(
            $keys,
            fn (mixed $key): bool => is_string($key) && $key !== 'customer',
        ));

        $resolved = $otherKeys !== [] ? EmailRecipientResolver::resolveRecipients($otherKeys) : [];

        return array_values(array_unique([...$emails, ...$resolved]));
    }
}

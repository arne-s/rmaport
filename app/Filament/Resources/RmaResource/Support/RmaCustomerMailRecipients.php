<?php

namespace App\Filament\Resources\RmaResource\Support;

use App\Models\Customer;
use App\Models\Rma;

final class RmaCustomerMailRecipients
{
    /**
     * @return array<string, string>
     */
    public static function recipientOptions(Rma $rma): array
    {
        $options = [];

        if ($rma->customer instanceof Customer && filled($rma->customer->getEmail())) {
            $options['customer'] = $rma->customer->getName().' <'.$rma->customer->getEmail().'>';
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function defaultToRecipients(Rma $rma): array
    {
        if ($rma->customer instanceof Customer && filled($rma->customer->getEmail())) {
            return ['customer'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    public static function resolveEmails(Rma $rma, array $keys): array
    {
        $emails = [];

        foreach ($keys as $key) {
            if ($key === 'customer' && $rma->customer instanceof Customer && filled($rma->customer->getEmail())) {
                $emails[] = (string) $rma->customer->getEmail();
            }
        }

        return array_values(array_unique($emails));
    }
}

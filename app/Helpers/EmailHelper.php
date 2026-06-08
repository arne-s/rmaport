<?php

namespace App\Helpers;

use App\Models\Customer;

final class EmailHelper
{
    public static function isValid(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function emailsEqualIgnoringCase(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || $a === '' || $b === '') {
            return false;
        }

        return strcasecmp(trim($a), trim($b)) === 0;
    }

    /**
     * Whether CC on the billing customer would duplicate the primary recipient (same record or same e-mail as To).
     */
    public static function billingCcDuplicatesPrimaryRecipient(
        ?Customer $primaryCustomer,
        Customer $billingCustomer,
        ?string $primaryToEmail,
    ): bool {
        if ($primaryCustomer !== null && $primaryCustomer->is($billingCustomer)) {
            return true;
        }

        if (! self::isValid($primaryToEmail)) {
            return false;
        }

        $billingEmail = $billingCustomer->getEmail();

        return self::isValid($billingEmail) && self::emailsEqualIgnoringCase($primaryToEmail, $billingEmail);
    }
}

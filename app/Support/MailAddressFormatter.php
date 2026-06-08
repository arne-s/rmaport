<?php

namespace App\Support;

use Symfony\Component\Mime\Address;

final class MailAddressFormatter
{
    public static function formatAddress(Address $address): string
    {
        $name = $address->getName();
        $email = $address->getAddress();

        return $name !== '' ? "{$name} <{$email}>" : $email;
    }

    /**
     * @param  list<Address>  $addresses
     */
    public static function formatAddressList(array $addresses): ?string
    {
        if ($addresses === []) {
            return null;
        }

        return implode(', ', array_map(self::formatAddress(...), $addresses));
    }

    public static function decodeAddressHeader(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = mb_decode_mimeheader($value);

        return $decoded !== false ? $decoded : $value;
    }
}

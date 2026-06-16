<?php

namespace App\Support\FormImport;

final class RmaFieldRegistry
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'uid' => 'RMA-nummer',
            'customer_id' => 'Klant',
            'import_row_id' => 'Importregel',
            'product_id' => 'Product',
            'accessories' => 'Accessoires',
            'return_reason' => 'Retourreden',
            'packing_slip_number' => 'Pakbon',
            'payment_method' => 'Betaalmethode',
            'complaint' => 'Klacht',
            'service' => 'Werkzaamheden',
            'notes' => 'Interne notities',
            'return_date' => 'Retourdatum',
        ];
    }

    public static function label(string $field): string
    {
        return self::options()[$field] ?? $field;
    }

    public static function isAllowed(?string $field): bool
    {
        if ($field === null || $field === '') {
            return false;
        }

        return array_key_exists($field, self::options());
    }

    /**
     * @return list<string>
     */
    public static function allowedFields(): array
    {
        return array_keys(self::options());
    }
}

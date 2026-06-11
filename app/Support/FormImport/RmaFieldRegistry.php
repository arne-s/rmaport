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
            'reference' => 'Referentie',
            'order_nr' => 'Ordernummer',
            'barcode' => 'Barcode',
            'defect_id' => 'Defect ID',
            'global_id' => 'Global ID',
            'ean' => 'EAN',
            'article_number' => 'Artikelnummer',
            'brand' => 'Merk',
            'product_group' => 'Artikelgroep',
            'product_name' => 'Product',
            'serial_number' => 'Serienummer',
            'imei' => 'IMEI',
            'product_condition' => 'Staat van product',
            'graded_type' => 'Graded type',
            'accessories' => 'Accessoires',
            'return_reason' => 'Retourreden',
            'return_sub_reason' => 'Sub-reden',
            'location_name' => 'Vestiging',
            'location_code' => 'Vestigingcode',
            'external_location_id' => 'Vestiging-ID',
            'language' => 'Taal',
            'purchased_at' => 'Aankoopdatum',
            'packing_slip_number' => 'Pakbon',
            'payment_method' => 'Betaalmethode',
            'complaint' => 'Klacht',
            'service' => 'Werkzaamheden',
            'notes' => 'Interne notities',
            'received_at' => 'Ontvangen op',
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

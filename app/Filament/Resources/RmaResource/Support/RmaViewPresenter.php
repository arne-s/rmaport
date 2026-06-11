<?php

namespace App\Filament\Resources\RmaResource\Support;

use App\Models\Rma;
use BackedEnum;

final class RmaViewPresenter
{
    /**
     * @return list<array{label: string, value: string}>
     */
    public static function generalFields(Rma $rma): array
    {
        return [
            ['label' => 'RMA Nummer', 'value' => self::text($rma->uid)],
            ['label' => 'Klant', 'value' => self::text($rma->customer?->getName())],
            ['label' => 'Status', 'value' => self::text($rma->status?->getLabel())],
            ['label' => 'Referentie', 'value' => self::text($rma->reference)],
            ['label' => 'Ordernummer', 'value' => self::text($rma->order_nr)],
            ['label' => 'Defect ID', 'value' => self::text($rma->defect_id)],
            ['label' => 'Global ID', 'value' => self::text($rma->global_id)],
            ['label' => 'Barcode', 'value' => self::text($rma->barcode)],
            ['label' => 'Aantal', 'value' => self::text((string) $rma->quantity)],
            ['label' => 'Pakbon', 'value' => self::text($rma->packing_slip_number)],
            ['label' => 'Betalingsmethode', 'value' => self::text($rma->payment_method?->getLabel())],
            ['label' => 'Vestiging', 'value' => self::text($rma->location_name)],
            ['label' => 'Vestigingcode', 'value' => self::text($rma->location_code)],
            ['label' => 'Vestiging-ID', 'value' => self::text($rma->external_location_id)],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function productFields(Rma $rma): array
    {
        return [
            ['label' => 'EAN', 'value' => self::text($rma->ean)],
            ['label' => 'Artikelnummer', 'value' => self::text($rma->article_number)],
            ['label' => 'Merk', 'value' => self::text($rma->brand?->getLabel())],
            ['label' => 'Artikelgroep', 'value' => self::text($rma->product_group)],
            ['label' => 'Product', 'value' => self::text($rma->product_name)],
            ['label' => 'Serienummer', 'value' => self::text($rma->serial_number)],
            ['label' => 'IMEI', 'value' => self::text($rma->imei)],
            ['label' => 'Staat van product', 'value' => self::text($rma->product_condition)],
            ['label' => 'Graded type', 'value' => self::text($rma->graded_type)],
            ['label' => 'Accessoires', 'value' => self::text($rma->accessories)],
            ['label' => 'Taal', 'value' => self::text($rma->language)],
            ['label' => 'Aankoopdatum', 'value' => self::text($rma->purchased_at?->format('d-m-Y'))],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function returnReadOnlyFields(Rma $rma): array
    {
        return [
            ['label' => 'Retourreden', 'value' => self::text($rma->return_reason)],
            ['label' => 'Sub-reden', 'value' => self::text($rma->return_sub_reason)],
            ['label' => 'Klacht', 'value' => self::text($rma->complaint)],
        ];
    }

    private static function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($value instanceof BackedEnum) {
            return (string) ($value->getLabel() ?? $value->value);
        }

        return (string) $value;
    }
}

<?php

namespace App\Filament\Resources\RmaResource\Support;

use App\Models\Rma;
use BackedEnum;

final class RmaViewPresenter
{
    /**
     * @return list<array{label: string, value: string}>
     */
    public static function generalPrimaryFields(Rma $rma): array
    {
        return [
            ['label' => 'RMA Nummer', 'value' => self::text($rma->uid)],
            ['label' => 'Status', 'value' => self::text($rma->status?->getLabel())],
            ['label' => 'Klant', 'value' => self::text($rma->customer?->getName())],
            ['label' => 'Aankoopdatum', 'value' => self::text($rma->purchased_at?->format('d-m-Y'))],
            ['label' => 'Betalingsmethode', 'value' => self::text($rma->payment_method?->getLabel())],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function generalDetailFields(Rma $rma): array
    {
        return [
            ['label' => 'Referentie', 'value' => self::text($rma->reference)],
            ['label' => 'Ordernummer', 'value' => self::text($rma->order_nr)],
            ['label' => 'Defect ID', 'value' => self::text($rma->defect_id)],
            ['label' => 'Global ID', 'value' => self::text($rma->global_id)],
            ['label' => 'Barcode', 'value' => self::text($rma->barcode)],
            ['label' => 'Pakbon', 'value' => self::text($rma->packing_slip_number)],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function generalFields(Rma $rma): array
    {
        return [
            ...self::generalPrimaryFields($rma),
            ...self::generalDetailFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function productFields(Rma $rma): array
    {
        return [
            ['label' => 'Artikelnaam', 'value' => self::text($rma->product_name)],
            ['label' => 'Aantal', 'value' => self::text((string) $rma->quantity)],
            ['label' => 'Artikelnummer', 'value' => self::text($rma->article_number)],
            ['label' => 'Merk', 'value' => self::text($rma->brand?->getLabel())],
            ['label' => 'EAN', 'value' => self::text($rma->ean)],
            ['label' => 'Artikelgroep', 'value' => self::text($rma->product_group)],
            ['label' => 'Serienummer', 'value' => self::text($rma->serial_number)],
            ['label' => 'IMEI', 'value' => self::text($rma->imei)],
            ['label' => 'Accessoires', 'value' => self::text($rma->accessories)],
            ['label' => 'Taal', 'value' => self::text($rma->language)],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function returnReadOnlyFields(Rma $rma): array
    {
        return [
            ['label' => 'Retourreden', 'value' => self::text($rma->return_reason)],
            ['label' => 'Staat van product', 'value' => self::text($rma->product_condition)],
            ['label' => 'Graded type', 'value' => self::text($rma->graded_type)],
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

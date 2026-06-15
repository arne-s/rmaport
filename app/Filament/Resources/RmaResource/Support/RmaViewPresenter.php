<?php

namespace App\Filament\Resources\RmaResource\Support;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\ImportRow;
use App\Models\Rma;
use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class RmaViewPresenter
{
    private const PRODUCT_NAME_LIMIT = 150;
    public static function combinedGeneralFields(Rma $rma): array
    {
        return [
            ...self::combinedGeneralHeaderFields($rma),
            ...self::combinedGeneralMiddleFields($rma),
            ...self::combinedGeneralFooterFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function combinedGeneralHeaderFields(Rma $rma): array
    {
        return [
            self::customerField($rma),
            ...self::customerContactFields($rma),
            ['label' => 'Invoerdatum en tijd', 'value' => self::text($rma->created_at?->format('d/m/y - H:i'))],
            ...self::generalDetailFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function combinedGeneralMiddleFields(Rma $rma): array
    {
        return self::importShipmentFields($rma);
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function combinedGeneralFooterFields(Rma $rma): array
    {
        return [
            ...self::productFields($rma),
            self::accessoiresField($rma),
            self::doaField($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function combinedGeneralDetailFields(Rma $rma): array
    {
        return [
            ...self::combinedGeneralMiddleFields($rma),
            ...self::combinedGeneralFooterFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function generalPrimaryFields(Rma $rma): array
    {
        return [
            ['label' => 'RMA Nummer', 'value' => self::text($rma->uid)],
            ['label' => 'Status', 'value' => self::text($rma->status?->getLabel())],
            self::customerField($rma),
            ...self::customerContactFields($rma),
            ...self::generalDetailFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function generalDetailFields(Rma $rma): array
    {
        $importRow = $rma->importRow;

        return [
            ['label' => 'Referentie', 'value' => self::text($importRow?->reference)],
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function generalFields(Rma $rma): array
    {
        return [
            ...self::generalPrimaryFields($rma),
            ...self::generalDetailFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function productFields(Rma $rma): array
    {
        $product = $rma->product;

        return [
            self::productNameField($product?->name, $product),
        ];
    }

    /**
     * @return array{label: string, value: string}
     */
    private static function accessoiresField(Rma $rma): array
    {
        $importRow = $rma->importRow;

        return [
            'label' => 'Accessoires',
            'value' => self::text($rma->accessories ?? $importRow?->accessories),
        ];
    }

    /**
     * @return array{label: string, value: string}
     */
    private static function doaField(Rma $rma): array
    {
        return [
            'label' => 'DOA',
            'value' => self::formatDoa($rma->importRow),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function importShipmentFields(Rma $rma): array
    {
        $importRow = $rma->importRow;
        $batch = $importRow?->importBatch;

        return [
            ['label' => 'Opdrachtnummer', 'value' => self::text($importRow?->assignment_nr)],
            ['label' => 'Zending-datum', 'value' => self::text($batch?->shipment_date?->format('d-m-Y'))],
            ['label' => 'Zending-referentie', 'value' => self::text($batch?->reference)],
            ['label' => 'Track & Trace', 'value' => self::text($batch?->track_trace_nr)],
        ];
    }

    private static function formatDoa(?ImportRow $importRow): string
    {
        if ($importRow === null) {
            return '(onbekend)';
        }

        return $importRow->is_doa ? 'Ja' : 'Nee';
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function customerContactFields(Rma $rma): array
    {
        $customer = $rma->customer;

        return [
            [
                'label' => 'E-mail',
                'value' => self::text($customer?->getEmail()),
            ],
            [
                'label' => 'Telefoonnummer',
                'value' => self::text($customer?->getAvailablePhoneNumber()),
            ],
        ];
    }

    /**
     * @return array{label: string, value: string, url?: string}
     */
    private static function customerField(Rma $rma): array
    {
        $customer = $rma->customer;

        return self::field(
            'Klant',
            self::text($customer?->getName()),
            $customer !== null ? CustomerResource::getUrl('edit', ['record' => $customer]) : null,
        );
    }

    /**
     * @return array{label: string, value: string, url?: string}
     */
    private static function productNameField(?string $name, ?Product $product): array
    {
        $displayName = $name !== null && $name !== '' ? $name : null;
        $value = self::text(
            $displayName !== null ? Str::limit($displayName, self::PRODUCT_NAME_LIMIT) : null,
        );

        $field = self::field(
            'Artikelnaam',
            $value,
            $product !== null && $displayName !== null ? ProductResource::getUrl('edit', ['record' => $product]) : null,
        );

        if ($displayName !== null && mb_strlen($displayName) > self::PRODUCT_NAME_LIMIT) {
            $field['title'] = $displayName;
        }

        $field['truncate'] = true;

        return $field;
    }

    /**
     * @return array{label: string, value: string, url?: string}
     */
    private static function field(string $label, string $value, ?string $url = null): array
    {
        $field = [
            'label' => $label,
            'value' => $value,
        ];

        if ($url !== null) {
            $field['url'] = $url;
        }

        return $field;
    }

    /**
     * @return list<array{label: string, value: string, url?: string}>
     */
    public static function returnReadOnlyFields(Rma $rma): array
    {
        return [
            ...self::returnReadOnlyPrimaryFields($rma),
            ...self::returnReadOnlySecondaryFields($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function returnReadOnlyPrimaryFields(Rma $rma): array
    {
        return [
            self::purchaseDateField($rma),
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function returnReadOnlySecondaryFields(Rma $rma): array
    {
        $importRow = $rma->importRow;

        return [
            self::returnDateField($rma),
            ['label' => 'Retourreden', 'value' => self::text($rma->return_reason ?? $importRow?->return_reason)],
        ];
    }

    /**
     * @return array{label: string, value: string}
     */
    private static function purchaseDateField(Rma $rma): array
    {
        return [
            'label' => 'Aankoopdatum',
            'value' => self::text($rma->importRow?->purchase_date?->format('d-m-Y')),
        ];
    }

    /**
     * @return array{label: string, value: string}
     */
    private static function returnDateField(Rma $rma): array
    {
        $returnDate = self::resolveReturnDate($rma);

        if ($returnDate === null) {
            return [
                'label' => 'Retourdatum',
                'value' => '(onbekend)',
            ];
        }

        $value = self::formatReturnDate($returnDate);
        $purchaseDate = $rma->importRow?->purchase_date;

        if ($purchaseDate !== null) {
            $days = (int) $purchaseDate->startOfDay()->diffInDays($returnDate->copy()->startOfDay());
            $dayLabel = $days === 1 ? 'dag' : 'dagen';
            $value .= " ({$days} {$dayLabel} na aankoop)";
        }

        return [
            'label' => 'Retourdatum',
            'value' => $value,
        ];
    }

    private static function resolveReturnDate(Rma $rma): ?Carbon
    {
        $importRow = $rma->importRow;

        if ($importRow?->return_date !== null) {
            return $importRow->return_date->copy()->startOfDay();
        }

        if ($importRow?->received_at !== null) {
            return $importRow->received_at->copy()->startOfDay();
        }

        if ($rma->received_at !== null) {
            return $rma->received_at->copy()->startOfDay();
        }

        return null;
    }

    private static function formatReturnDate(Carbon $date): string
    {
        $month = mb_strtolower(rtrim($date->translatedFormat('M'), '.').'.');

        return sprintf('%s %s %s', $date->translatedFormat('j'), $month, $date->translatedFormat('Y'));
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

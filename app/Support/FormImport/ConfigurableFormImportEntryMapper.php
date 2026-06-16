<?php

namespace App\Support\FormImport;

use App\Enums\RmaStatus;
use App\Models\FormImport;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;

class ConfigurableFormImportEntryMapper
{
    use MapsRmaImportRows;

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function map(FormImport $formImport, array $entry): array
    {
        $fieldValues = $this->extractFieldValues($entry);
        $attributes = [];

        foreach ($formImport->fieldMappings as $mapping) {
            if (filled($mapping->fixed_value)) {
                $value = $this->nullableString($mapping->fixed_value);
            } else {
                $value = $this->nullableString($this->stringifyFieldValue($fieldValues[$mapping->source_field_id] ?? null));
            }

            if ($value === null) {
                continue;
            }

            if (! RmaFieldRegistry::isAllowed($mapping->rma_field)) {
                continue;
            }

            $attributes[$mapping->rma_field] = $this->castRmaField($mapping->rma_field, $value);
        }

        $entryId = (int) ($entry['id'] ?? 0);
        $attributes['uid'] = $this->resolveUid(
            $this->uidFromConfiguredField($formImport, $fieldValues),
            $this->fallbackUid($formImport, $entryId),
        );
        $attributes['return_date'] ??= $this->parseDate((string) ($entry['date_created'] ?? ''), 'Y-m-d H:i:s')
            ?? $this->parseDate((string) ($entry['date_created'] ?? ''), 'Y-m-d');
        $attributes['status'] = RmaStatus::Open->value;

        return array_filter($attributes, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function extractFieldValues(array $entry): array
    {
        $reserved = [
            'id', 'form_id', 'date_created', 'date_updated', 'is_starred', 'is_read',
            'ip', 'source_url', 'post_id', 'created_by', 'user_agent', 'status',
            'payment_status', 'payment_date', 'payment_amount', 'payment_method',
            'transaction_id', 'transaction_type', 'is_fulfilled', 'currency',
            '_labels', 'total_count', 'entries',
        ];

        $values = [];

        foreach ($entry as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $key = (string) $key;

            if (in_array($key, $reserved, true)) {
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $fieldValues
     */
    private function uidFromConfiguredField(FormImport $formImport, array $fieldValues): ?string
    {
        if (blank($formImport->uid_source_field_id)) {
            return null;
        }

        return $this->nullableString(
            $this->stringifyFieldValue($fieldValues[$formImport->uid_source_field_id] ?? null),
        );
    }

    private function fallbackUid(FormImport $formImport, int $entryId): string
    {
        $prefix = $formImport->uid_fallback_prefix ?: 'FI';

        return mb_substr("{$prefix}{$formImport->source_form_id}-{$entryId}", 0, 20);
    }

    private function castRmaField(string $field, string $value): mixed
    {
        return match ($field) {
            'customer_id', 'import_row_id', 'product_id' => (int) $value,
            'return_date' => $this->parseDate($value, 'Y-m-d')
                ?? $this->parseDate($value, 'd.m.Y')
                ?? $this->parseDate($value, 'd-m-Y')
                ?? $value,
            default => $value,
        };
    }

    private function stringifyFieldValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $parts = array_filter(array_map(
                fn (mixed $part): ?string => is_scalar($part) ? trim((string) $part) : null,
                $value,
            ));

            return $parts === [] ? null : implode(', ', $parts);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}

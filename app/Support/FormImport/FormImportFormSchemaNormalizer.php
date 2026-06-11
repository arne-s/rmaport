<?php

namespace App\Support\FormImport;

final class FormImportFormSchemaNormalizer
{
    /**
     * @param  array<string, mixed>  $form
     * @return list<array{id: string, label: string, type: string}>
     */
    public function normalizeFields(array $form): array
    {
        $fields = [];

        foreach ($form['fields'] ?? [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fields = array_merge($fields, $this->normalizeField($field));
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<array{id: string, label: string, type: string}>
     */
    private function normalizeField(array $field): array
    {
        $type = (string) ($field['type'] ?? 'text');
        $label = trim((string) ($field['label'] ?? $field['adminLabel'] ?? 'Veld'));
        $inputs = $field['inputs'] ?? null;

        if (is_array($inputs) && $inputs !== []) {
            $normalized = [];

            foreach ($inputs as $input) {
                if (! is_array($input)) {
                    continue;
                }

                $inputId = (string) ($input['id'] ?? '');

                if ($inputId === '') {
                    continue;
                }

                $inputLabel = trim((string) ($input['label'] ?? $label));

                $normalized[] = [
                    'id' => $inputId,
                    'label' => $inputLabel !== '' ? "{$label} — {$inputLabel}" : $label,
                    'type' => $type,
                ];
            }

            if ($normalized !== []) {
                return $normalized;
            }
        }

        $fieldId = (string) ($field['id'] ?? '');

        if ($fieldId === '') {
            return [];
        }

        return [[
            'id' => $fieldId,
            'label' => $label !== '' ? $label : "Veld {$fieldId}",
            'type' => $type,
        ]];
    }
}

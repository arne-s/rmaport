<?php

namespace App\Support;

final class PackingSlipChecklist
{
    public static function defaultType(): string
    {
        $type = config('packing_slip_checklist.default_type', 'adl');

        return self::isValidType($type) ? $type : 'adl';
    }

    public static function isValidType(?string $type): bool
    {
        if ($type === null || $type === '') {
            return false;
        }

        return array_key_exists($type, self::types());
    }

    public static function resolveType(?string $type): string
    {
        return self::isValidType($type) ? $type : self::defaultType();
    }

    public static function resolveTypeFromFittingType(mixed $fittingType): string
    {
        if (! is_string($fittingType) || trim($fittingType) === '') {
            return self::defaultType();
        }

        $fittingType = trim($fittingType);

        foreach (self::types() as $typeKey => $definition) {
            $label = trim((string) ($definition['label'] ?? ''));

            if ($label !== '' && strcasecmp($label, $fittingType) === 0) {
                return $typeKey;
            }
        }

        /** @var array<string, string> $fittingTypeMap */
        $fittingTypeMap = [
            'ADL rolstoel' => 'adl',
            'Sport rolstoel' => 'adl',
            'PAWS' => 'paws',
            'Handbike' => 'apv',
            'Free/Trackwheel' => 'swiss_trac',
        ];

        if (isset($fittingTypeMap[$fittingType]) && self::isValidType($fittingTypeMap[$fittingType])) {
            return $fittingTypeMap[$fittingType];
        }

        $normalized = mb_strtolower($fittingType);

        if (str_contains($normalized, 'paws')) {
            return 'paws';
        }

        if (str_contains($normalized, 'handbike') || str_contains($normalized, 'apv')) {
            return 'apv';
        }

        if (str_contains($normalized, 'swiss') || str_contains($normalized, 'trac') || str_contains($normalized, 'track')) {
            return 'swiss_trac';
        }

        if (str_contains($normalized, 'adl') || str_contains($normalized, 'sport')) {
            return 'adl';
        }

        return self::defaultType();
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $options = [];
        foreach (self::types() as $key => $definition) {
            $options[$key] = (string) ($definition['label'] ?? $key);
        }

        return $options;
    }

    /**
     * @return array<string, array{label: string, intro: string, items: array<string, string>, outro: array<int, string>}>
     */
    public static function types(): array
    {
        /** @var array<string, array{label?: string, intro?: string, items?: array<string, string>, outro?: array<int, string>}> $types */
        $types = config('packing_slip_checklist.types', []);

        return $types;
    }

    /**
     * @return array<string, string>
     */
    public static function itemsForType(string $type): array
    {
        $definition = self::types()[self::resolveType($type)] ?? [];

        return $definition['items'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public static function itemKeysForType(string $type): array
    {
        return array_keys(self::itemsForType($type));
    }

    public static function introForType(string $type): string
    {
        $definition = self::types()[self::resolveType($type)] ?? [];

        return (string) ($definition['intro'] ?? '');
    }

    /**
     * @return array<int, string>
     */
    public static function outroForType(string $type): array
    {
        $definition = self::types()[self::resolveType($type)] ?? [];

        return $definition['outro'] ?? [];
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveLabels(string $type, array $selectedKeys): array
    {
        $items = self::itemsForType($type);
        $selected = array_flip($selectedKeys);
        $labels = [];

        foreach ($items as $key => $label) {
            if (isset($selected[$key])) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public static function labelForType(string $type): string
    {
        $definition = self::types()[self::resolveType($type)] ?? [];

        return (string) ($definition['label'] ?? $type);
    }

    /**
     * @return list<array{key: string, label: string, kind: string, group?: string, group_label?: string, text_suffix?: string}>
     */
    public static function deliveryProofForType(string $type): array
    {
        $definition = self::types()[self::resolveType($type)] ?? [];

        /** @var list<array{key: string, label: string, kind: string, group?: string, group_label?: string, text_suffix?: string}> $items */
        $items = $definition['delivery_proof'] ?? [];

        return $items;
    }

    /**
     * @param  array<int, string>  $checkedKeys
     * @return array<string, string>
     */
    public static function deliveryProofOptionsForType(string $type, array $checkedKeys = []): array
    {
        $options = [];
        $checked = array_flip($checkedKeys);

        foreach (self::deliveryProofForType($type) as $item) {
            if (! self::isDeliveryProofItemVisibleInForm($item, $checked)) {
                continue;
            }

            $group = $item['group'] ?? null;
            $label = $item['label'];

            if ($group === 'speed_limit') {
                $suffix = trim((string) ($item['text_suffix'] ?? 'km/uur'));
                $label = 'Begrensd op ' . $item['label'] . ' ' . $suffix;
            }

            $options[$item['key']] = $label;
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $checkedKeys
     * @return list<string>
     */
    public static function pruneDeliveryProofItems(string $type, array $checkedKeys): array
    {
        $checkedKeys = self::enforceExclusiveDeliveryProofGroups($type, $checkedKeys);
        $checked = array_flip($checkedKeys);
        $pruned = [];

        foreach ($checkedKeys as $key) {
            $item = self::deliveryProofItemByKey($type, $key);

            if ($item === null) {
                continue;
            }

            if (self::isDeliveryProofItemVisibleInForm($item, $checked)) {
                $pruned[] = $key;
            }
        }

        return array_values(array_unique($pruned));
    }

    /**
     * @param  array<int, string>  $checkedKeys
     * @return list<string>
     */
    public static function enforceExclusiveDeliveryProofGroups(string $type, array $checkedKeys): array
    {
        /** @var array<string, list<string>> $groupedKeys */
        $groupedKeys = [];

        foreach ($checkedKeys as $key) {
            $item = self::deliveryProofItemByKey($type, $key);

            if ($item === null) {
                continue;
            }

            $exclusiveGroup = $item['exclusive_group'] ?? null;

            if (! is_string($exclusiveGroup) || $exclusiveGroup === '') {
                continue;
            }

            $groupedKeys[$exclusiveGroup][] = $key;
        }

        $keysToRemove = [];

        foreach ($groupedKeys as $keys) {
            if (count($keys) <= 1) {
                continue;
            }

            $keysToRemove = [...$keysToRemove, ...array_slice($keys, 0, -1)];
        }

        if ($keysToRemove === []) {
            return array_values(array_unique($checkedKeys));
        }

        return array_values(array_diff($checkedKeys, $keysToRemove));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $checked
     */
    private static function isDeliveryProofItemVisibleInForm(array $item, array $checked): bool
    {
        /** @var list<string> $requires */
        $requires = $item['requires'] ?? [];

        foreach ($requires as $requiredKey) {
            if (! isset($checked[$requiredKey])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{key: string, label: string, kind: string, group?: string, group_label?: string, text_suffix?: string, requires?: list<string>}|null
     */
    private static function deliveryProofItemByKey(string $type, string $key): ?array
    {
        foreach (self::deliveryProofForType($type) as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function deliveryProofKeysForType(string $type): array
    {
        return array_map(
            static fn (array $item): string => $item['key'],
            self::deliveryProofForType($type),
        );
    }

    /**
     * @return list<string>
     */
    public static function deliveryProofTextFieldKeysForType(string $type): array
    {
        $keys = [];

        foreach (self::deliveryProofForType($type) as $item) {
            if (($item['kind'] ?? '') === 'checkbox_text') {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, string>  $checkedKeys
     * @param  array<string, string|null>  $textValues
     * @return list<array{kind: string, key?: string, label: string, checked: bool, text: string, text_separator?: string, indent_px?: int, spacer_after?: bool}>
     */
    public static function formatDeliveryProofForPdf(string $type, array $checkedKeys, array $textValues): array
    {
        $type = self::resolveType($type);
        $checked = array_flip($checkedKeys);
        $lines = [];
        $seenGroups = [];

        foreach (self::deliveryProofForType($type) as $item) {
            $key = $item['key'];
            $group = $item['group'] ?? null;

            if ($group !== null && $group !== '' && ! isset($seenGroups[$group])) {
                $seenGroups[$group] = true;
                $lines[] = [
                    'kind' => 'group_header',
                    'label' => (string) ($item['group_label'] ?? $group),
                    'checked' => false,
                    'text' => '',
                    'indent_px' => self::deliveryProofIndentPx($item),
                    'spacer_after' => false,
                ];
            }

            $label = $item['label'];
            $text = trim((string) ($textValues[$key] ?? ''));

            if (($item['kind'] ?? '') === 'checkbox_text') {
                $suffix = trim((string) ($item['text_suffix'] ?? ''));
                $displayLabel = $suffix !== '' ? $label . ' ' . $suffix : $label;

                $lines[] = [
                    'kind' => 'item',
                    'key' => $key,
                    'label' => $displayLabel,
                    'checked' => isset($checked[$key]),
                    'text' => $text,
                    'text_separator' => self::deliveryProofTextSeparator($displayLabel, $text),
                    'indent_px' => self::deliveryProofIndentPx($item),
                    'spacer_after' => self::deliveryProofSpacerAfter($key),
                ];

                continue;
            }

            if ($group === 'speed_limit') {
                $label = $item['label'] . ' km/uur';
            }

            $lines[] = [
                'kind' => 'item',
                'key' => $key,
                'label' => $label,
                'checked' => isset($checked[$key]),
                'text' => '',
                'indent_px' => self::deliveryProofIndentPx($item),
                'spacer_after' => self::deliveryProofSpacerAfter($key),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function deliveryProofIndentPx(array $item): int
    {
        if (($item['group'] ?? null) === 'speed_limit') {
            return 0;
        }

        return 0;
    }

    private static function deliveryProofSpacerAfter(string $key): bool
    {
        return in_array($key, ['tourer_20', 'off_the_road'], true);
    }

    private static function deliveryProofTextSeparator(string $displayLabel, string $text): string
    {
        if ($text === '') {
            return '';
        }

        return str_ends_with(rtrim($displayLabel), ':') ? ' ' : ': ';
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyDeliveryProofTextState(string $type): array
    {
        $state = [];

        foreach (self::deliveryProofTextFieldKeysForType($type) as $key) {
            $state[$key] = '';
        }

        return $state;
    }
}

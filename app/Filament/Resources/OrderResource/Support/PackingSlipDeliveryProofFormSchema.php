<?php

namespace App\Filament\Resources\OrderResource\Support;

use App\Support\PackingSlipChecklist;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

final class PackingSlipDeliveryProofFormSchema
{
    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function components(): array
    {
        return [
            CheckboxList::make('delivery_proof_items')
                ->hiddenLabel()
                ->extraFieldWrapperAttributes(function (Get $get): array {
                    $baseClass = 'checkbox-compact mt-4 packing-slip-delivery-proof-options';
                    $isPaws = PackingSlipChecklist::resolveType($get('checklist_type')) === 'paws';

                    return ['class' => $isPaws ? $baseClass . ' is-paws' : $baseClass];
                })
                ->options(function (Get $get): array {
                    $checked = $get('delivery_proof_items');

                    return PackingSlipChecklist::deliveryProofOptionsForType(
                        PackingSlipChecklist::resolveType($get('checklist_type')),
                        is_array($checked) ? $checked : [],
                    );
                })
                ->live()
                ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                    $type = PackingSlipChecklist::resolveType($get('checklist_type'));
                    $pruned = PackingSlipChecklist::pruneDeliveryProofItems($type, is_array($state) ? $state : []);

                    if ($pruned !== $state) {
                        $set('delivery_proof_items', $pruned);
                    }
                })
                ->columns(1)
                ->columnSpanFull(),
            Group::make()
                ->schema(fn (Get $get): array => self::textFieldsForType(
                    PackingSlipChecklist::resolveType($get('checklist_type')),
                ))
                ->extraAttributes([
                    'class' => 'custom-form-design packing-slip-delivery-proof-text-fields',
                    'style' => 'margin-top: 8px',
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return list<TextInput>
     */
    private static function textFieldsForType(string $type): array
    {
        $fields = [];

        foreach (PackingSlipChecklist::deliveryProofForType($type) as $item) {
            if (($item['kind'] ?? '') !== 'checkbox_text') {
                continue;
            }

            $key = $item['key'];
            $suffix = trim((string) ($item['text_suffix'] ?? ''));
            $label = $suffix !== '' ? $item['label'] . ' ' . $suffix : $item['label'];

            $fields[] = TextInput::make('delivery_proof_text.' . $key)
                ->label($label)
                ->inlineLabel()
                ->maxLength(255)
                ->visible(fn (Get $get): bool => self::isDeliveryProofItemChecked($get, $key))
                ->columnSpanFull();
        }

        return $fields;
    }

    private static function isDeliveryProofItemChecked(Get $get, string $key): bool
    {
        $checked = $get('delivery_proof_items');

        if (! is_array($checked)) {
            return false;
        }

        return in_array($key, $checked, true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyStateForType(string $type): array
    {
        return [
            'delivery_proof_items' => [],
            'delivery_proof_text' => PackingSlipChecklist::emptyDeliveryProofTextState($type),
        ];
    }

    public static function resetDeliveryProofForType(Set $set, string $type): void
    {
        $set('delivery_proof_items', []);
        $set('delivery_proof_text', PackingSlipChecklist::emptyDeliveryProofTextState($type));
    }
}

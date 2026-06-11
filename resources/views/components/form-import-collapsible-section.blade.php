@props([
    'heading',
    'collapseId',
    'collapsed' => false,
    'persistCollapsed' => false,
    'description' => null,
])

<x-filament::section
    :heading="$heading"
    :description="$description"
    heading-tag="h2"
    collapsible
    :persist-collapsed="$persistCollapsed"
    :collapse-id="$collapseId"
    :collapsed="$collapsed"
    {{ $attributes->class([
        'beheer-bedrijfsgegevensSection',
        'header-bedrijfsgegevens',
        'settingspage-payment-section',
        'form-import-section',
    ]) }}
>
    {{ $slot }}
</x-filament::section>

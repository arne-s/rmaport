@props([
    'userId',
    'tokenId' => null,
    'selected' => null,
    'categoryOptions' => [],
    'allCategoryOptions' => [],
])

@php
    $lookupOptions = $allCategoryOptions !== [] ? $allCategoryOptions : $categoryOptions;

    $selectedCategory = filled($selected)
        ? collect($lookupOptions)->firstWhere('name', $selected)
        : null;

    $fieldId = 'outlook-category-' . ($tokenId ?? 'x') . '-' . $userId;
@endphp

<div
    wire:key="outlook-category-select-{{ $userId }}-{{ md5(json_encode($categoryOptions)) }}"
    class="outlook-category-select relative w-full"
    x-data="{ open: false }"
    @click.outside="open = false"
>
    <button
        id="{{ $fieldId }}"
        type="button"
        @click="open = !open"
        class="microsoft-outlook-settings__select outlook-category-select__trigger text-left"
        aria-haspopup="listbox"
        :aria-expanded="open"
    >
        @if ($selectedCategory)
            <span
                class="outlook-category-option truncate"
                style="background-color: {{ $selectedCategory['color'] }};"
            >{{ $selectedCategory['name'] }}</span>
        @else
            <span class="text-gray-500">Selecteer een optie</span>
        @endif
    </button>

    <div
        x-show="open"
        x-transition
        x-cloak
        class="outlook-category-select__panel absolute z-50 mt-1 max-h-48 w-full overflow-y-auto"
        role="listbox"
    >
        <button
            type="button"
            wire:click="setUserCategory({{ $userId }}, null)"
            @click="open = false"
            class="outlook-category-select__option text-gray-500"
            role="option"
        >
            Geen categorie
        </button>

        @foreach ($categoryOptions as $option)
            <button
                type="button"
                wire:click="setUserCategory({{ $userId }}, @js($option['name']))"
                @click="open = false"
                class="outlook-category-select__option"
                role="option"
            >
                <span
                    class="outlook-category-option block truncate"
                    style="background-color: {{ $option['color'] }};"
                >{{ $option['name'] }}</span>
            </button>
        @endforeach
    </div>
</div>

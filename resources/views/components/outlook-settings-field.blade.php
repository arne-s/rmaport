@props([
    'label',
    'for' => null,
    'controlClass' => '',
])

<div {{ $attributes->class(['microsoft-outlook-settings__row']) }}>
    @if ($for)
        <label for="{{ $for }}" class="microsoft-outlook-settings__label">{{ $label }}</label>
    @else
        <span class="microsoft-outlook-settings__label">{{ $label }}</span>
    @endif

    <div @class(['microsoft-outlook-settings__control', $controlClass])>
        {{ $slot }}
    </div>
</div>

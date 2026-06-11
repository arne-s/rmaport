@props([
    'label' => '',
    'for' => null,
    'hint' => null,
    'wrapInput' => false,
    'hideLabel' => false,
])

<div {{ $attributes->class([
    'fi-fo-field fi-fo-field-has-inline-label',
    'form-import-field--label-invisible' => $hideLabel,
]) }}>
    <div @class([
        'fi-fo-field-label-col fi-vertical-align-center',
        'form-import-label-col--invisible' => $hideLabel,
    ])>
        <div class="fi-fo-field-label-ctn">
            <div class="fi-fo-field-label">
                @if ($for)
                    <label for="{{ $for }}" class="fi-fo-field-label-content">{{ $label }}</label>
                @else
                    <span class="fi-fo-field-label-content">{{ $hideLabel ? 'Mapping' : $label }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="fi-fo-field-content-col">
        @if ($wrapInput)
            <div class="fi-input-wrp">
                <div class="fi-input-wrp-content-ctn">
                    {{ $slot }}
                </div>
            </div>
        @else
            {{ $slot }}
        @endif

        @if ($hint)
            <p class="fi-sc-text">{{ $hint }}</p>
        @endif
    </div>
</div>

@php
    /** @var array{label?: string, url?: string|null}|mixed $state */
    $state = $getState();
    $label = is_array($state) ? (string) ($state['label'] ?? '-') : (string) $state;
    $url = is_array($state) ? ($state['url'] ?? null) : null;
@endphp

@if (is_string($url) && $url !== '')
    <a href="{{ $url }}" style="color: #0295c8; text-decoration: underline; font-size: 12px;">
        {{ $label }}
    </a>
@else
    {{ $label }}
@endif

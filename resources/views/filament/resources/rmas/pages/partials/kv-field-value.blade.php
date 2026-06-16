@if (! empty($field['url']))
    <a
        href="{{ $field['url'] }}"
        class="rmasTab__kv-link @if (! empty($field['truncate'])) rmasTab__kv-link--truncate @endif"
        @if (! empty($field['title'])) title="{{ $field['title'] }}" @endif
        @if (! empty($field['newTab'])) target="_blank" rel="noopener noreferrer" @endif
    >{{ $field['value'] }}</a>
@else
    {{ $field['value'] }}
@endif

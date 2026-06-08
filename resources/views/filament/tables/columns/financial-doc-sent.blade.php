@php
    $record = $getRecord();
    $label = is_array($record) ? ($record['sent_at'] ?? '-') : '-';
    $scheduledAt = is_array($record) ? ($record['sent_at_scheduled_at'] ?? null) : null;
    $isCountdown = is_string($label) && str_starts_with($label, 'Over ');
@endphp
<span
    @if ($scheduledAt instanceof \Carbon\CarbonInterface)
        title="Verzending gepland: {{ $scheduledAt->timezone(config('app.timezone'))->format('d-m-Y H:i') }}"
    @endif
    @if ($isCountdown)
        style="font-size: 12px; color: #333;"
    @endif
>{{ $label }}</span>

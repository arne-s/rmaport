@props([
    'details' => null,
    'holderName' => null,
    'lockedAt' => null,
    'expiresAt' => null,
    'backUrl' => null,
    'title' => 'Document in gebruik',
    'backLabel' => 'Terug naar overzicht',
])

@php
    $details = is_array($details) ? $details : [];
    $holderName = $holderName ?? ($details['holderName'] ?? '');
    $lockedAt = $lockedAt ?? ($details['lockedAt'] ?? '');
    $expiresAt = $expiresAt ?? ($details['expiresAt'] ?? '');
    $backUrl = $backUrl ?? ($details['backUrl'] ?? null);
@endphp

<div
    role="alert"
    {{ $attributes->class([
        'rounded-lg border border-danger-500/40 bg-danger-50 p-6 text-sm text-danger-950',
        'dark:border-danger-500/30 dark:bg-danger-950/30 dark:text-danger-100',
    ]) }}
>
    <p class="text-base font-semibold">{{ $title }}</p>
    <p class="mt-2">
        {{ $holderName }} bekijkt dit document momenteel (sinds {{ $lockedAt }}, geldig tot {{ $expiresAt }}).
    </p>
    @if (filled($backUrl))
        <p class="mt-4">
            <a
                href="{{ $backUrl }}"
                class="inline-flex items-center font-medium underline hover:no-underline"
            >
                {{ $backLabel }}
            </a>
        </p>
    @endif
</div>

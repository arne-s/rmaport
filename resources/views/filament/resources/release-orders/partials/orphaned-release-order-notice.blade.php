@php
    $backUrl = $backUrl ?? null;
@endphp
<div
    role="alert"
    class="mb-4 rounded-lg border border-amber-500/40 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-100"
>
    <p class="font-semibold">Dit afroepverzoek is niet meer geldig</p>
    <p class="mt-1">
        Er zijn geen artikelen meer gekoppeld aan dit document. Dat gebeurt meestal als er een nieuwe versie van de aanvraag is gestart
        en de artikelen aan een nieuw afroepverzoek zijn gekoppeld.
    </p>
    @if (filled($backUrl))
        <p class="mt-2">
            <a href="{{ $backUrl }}" class="font-medium underline hover:no-underline">Terug naar aanvraag</a>
        </p>
    @endif
</div>

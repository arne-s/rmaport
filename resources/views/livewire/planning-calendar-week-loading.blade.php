<div class="appointment-calendar-picker" wire:init="bootCalendar">
    <div class="acp-week-nav mb-2">
        <div class="acp-week-nav__toolbar flex items-center justify-between gap-2">
            <div class="acp-week-nav__nav-btn fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray inline-grid shrink-0 opacity-40">
                <span class="fi-btn-label">‹ Vorige week</span>
            </div>
            <span class="text-sm font-semibold text-gray-500">Agenda laden...</span>
            <div class="acp-week-nav__nav-btn fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray inline-grid shrink-0 opacity-40">
                <span class="fi-btn-label">Volgende week ›</span>
            </div>
        </div>
    </div>

    <div class="acp-grid-container overflow-hidden rounded-lg border border-gray-200">
        <div
            class="flex items-center justify-center rounded-lg border-0 bg-gray-50"
            style="height:min(585px, calc(100dvh - 220px)); max-height:calc(100dvh - 220px);"
        >
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <x-filament::loading-indicator class="fi-icon fi-size-md animate-spin" />
                <span>Agenda laden...</span>
            </div>
        </div>
    </div>
</div>

<button
    class="fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-500 fi-text-color-900 hover:fi-text-color-800 dark:fi-text-color-950 dark:hover:fi-text-color-950 fi-btn fi-size-md fi-ac-btn-action inline-flex items-center justify-center gap-2"
    type="button"
    :disabled="saving"
    x-on:click="runFooterSave($wire)"
>
    <x-filament::loading-indicator
        class="fi-icon fi-size-md animate-spin"
        x-show="saving"
        x-cloak
    />
    <span x-show="!saving">Opslaan</span>
</button>

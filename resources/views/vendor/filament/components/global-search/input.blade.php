<div {{ $attributes->class(['filament-global-search-input']) }}>
    <label for="globalSearchInput" class="sr-only">
        {{ __('filament::global-search.field.label') }}
    </label>

    <div class="relative group max-w-md">
        <span @class([
            'search-icon absolute inset-y-0 left-0 flex items-center justify-center
            w-18 h-18 text-gray-100 pointer-events-none group-focus-within:text-primary-500',
            'dark:text-gray-400' => config('filament.dark_mode'),
        ])>
            <x-heroicon-o-magnifying-glass class="w-7 h-7" wire:loading.remove.delay wire:target="search" />

            <x-filament::loading-indicator class="w-6 h-6" wire:loading.delay wire:target="search" />
        </span>

        <input
            wire:model.live.debounce.500ms="search"
            id="globalSearchInput"
            placeholder="{{ __('filament::global-search.field.placeholder') }}"
            type="search"
            autocomplete="off"
            @class([
                'block w-full h-14 pl-10 bg-gray-400/10 placeholder-gray-500 border-transparent transition duration-75 rounded-lg outline-hidden focus:bg-white focus:placeholder-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500',
                'dark:bg-gray-700 dark:text-gray-200 dark:placeholder-gray-400' => config('filament.dark_mode'),
            ])
        >
    </div>
</div>

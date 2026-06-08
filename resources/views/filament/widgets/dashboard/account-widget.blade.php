<x-filament-widgets::widget class="filament-account-widget">
    <x-filament::card>
        @php
            $user = \Filament\Facades\Filament::auth()->user();
        @endphp
        <div class="h-12 flex items-center space-x-4 rtl:space-x-reverse">
            <x-filament::user-avatar :user="$user" />

            <div class="flex-1 text-left">
                <h2 class="text-lg sm:text-xl font-bold tracking-tight flex flex-col sm:flex-row sm:gap-1">
                    <span>{{ __('filament::widgets/account-widget.welcome') }}</span>
                    <span>{{ \Filament\Facades\Filament::getUserName($user) }}</span>
                </h2>
            </div>
        </div>
    </x-filament::card>
</x-filament::widget>

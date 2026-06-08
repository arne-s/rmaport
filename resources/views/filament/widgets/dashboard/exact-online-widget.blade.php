@php

    @endphp

<x-filament-widgets::widget class="filament-widgets-chart-widget">
    <div {!! ($pollingInterval = $this->getPollingInterval()) ? "wire:poll.{$pollingInterval}" : '' !!}>
        <x-filament::stats :columns="$this->getColumns()">
            <x-filament::card>

                <div class="h-12 flex items-center space-x-4 rtl:space-x-reverse">
                    <div class="w-10 h-10 bg-gray-200 bg-cover bg-center"
                         style="background-image: url({{ asset('img/icons/exact-online-logo.png') }})"
                    ></div>
                    <div class="exact-online-widget__mobile">
                        <h2 class="text-md md:text-sm font-bold tracking-tight">
                            Exact Online
                        </h2>
                        <div class="sm:text-sm text-gray-600">
                            @if ($this->isConnected())
                                <strong style="color: green">Online</strong>
                            @else
                                <span><strong style="color: red">Geen verbinding</strong>
                                <a style="text-decoration: underline"
                                   href="{{ config('app.url').route('exact.connect',['t'=>time()], false) }}"
                                   target="_blank">verbind</a></span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-filament::card>


        </x-filament::stats>
    </div>


</x-filament::widget>

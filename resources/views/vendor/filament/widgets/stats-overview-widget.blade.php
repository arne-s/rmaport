<x-filament-widgets::widget class="filament-stats-overview-widget">
    {{-- <div class="backToPreviousPage">
        <a href="/beheer/reporting">Terug naar administratie</a>
    </div> --}}
    <div class="widgetsContainer" {!! ($pollingInterval = $this->getPollingInterval()) ? "wire:poll.{$pollingInterval}" : '' !!}>
        <x-filament::stats :columns="$this->getColumns()">
            @foreach ($this->getCachedCards() as $card)
                {{ $card }}
            @endforeach
        </x-filament::stats>
    </div>
</x-filament::widget>

<x-filament-panels::page class="filament-statistics-page">
    {{-- Chart.js comes from Filament’s x-load chart bundles, not a second global build. --}}
    <x-filament-widgets::widgets :widgets="$this->getWidgets()" :columns="$this->getColumns()" />
</x-filament-panels::page>

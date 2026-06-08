<x-filament-panels::page class="filament-dashboard-page">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <x-filament-widgets::widgets :widgets="$this->getVisibleWidgets()" :columns="$this->getColumns()" />
</x-filament-panels::page>
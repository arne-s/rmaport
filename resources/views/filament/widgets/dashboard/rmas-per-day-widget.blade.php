@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $hasMaxHeight = filled($maxHeight) && $maxHeight !== '100%';
    $hasData = filled($this->getCachedData()['labels'] ?? []);
@endphp

<x-filament-widgets::widget class="fi-wi-chart dashboard-paired-table-widget rmas-per-day-widget">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if ($hasData)
            <div
                @if ($pollingInterval = $this->getPollingInterval())
                    wire:poll.{{ $pollingInterval }}="updateChartData"
                @endif
                class="rmas-per-day-widget__chart-wrap"
            >
                <div
                    x-load
                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                    wire:ignore
                    data-chart-type="{{ $type }}"
                    x-data="chart({
                                cachedData: @js($this->getCachedData()),
                                options: @js($this->getOptions()),
                                type: @js($type),
                            })"
                    {{
                        (new ComponentAttributeBag)
                            ->color(ChartWidgetComponent::class, $color)
                            ->class([
                                'fi-wi-chart-canvas-ctn',
                                'fi-wi-chart-canvas-ctn-no-aspect-ratio' => $hasMaxHeight,
                            ])
                    }}
                >
                    <canvas
                        x-ref="canvas"
                        @style([
                            'width: 100%',
                            'height: 100%; max-height: 100%' => ! $hasMaxHeight,
                            ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                        ])
                    ></canvas>

                    <span
                        x-ref="backgroundColorElement"
                        class="fi-wi-chart-bg-color"
                    ></span>

                    <span
                        x-ref="borderColorElement"
                        class="fi-wi-chart-border-color"
                    ></span>

                    <span
                        x-ref="gridColorElement"
                        class="fi-wi-chart-grid-color"
                    ></span>

                    <span
                        x-ref="textColorElement"
                        class="fi-wi-chart-text-color"
                    ></span>
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Geen RMA's met aankoopdatum gevonden.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

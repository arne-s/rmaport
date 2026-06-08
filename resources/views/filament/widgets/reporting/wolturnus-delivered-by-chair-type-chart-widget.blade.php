@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
    $wolturnusTable = $this->getWolturnusTableData();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    <x-filament::input.wrapper
                        class="fi-wi-chart-filter w-auto max-w-[7.5rem] shrink-0"
                    >
                        <x-filament::input.select
                            wire:model.live="filter"
                            class="!ps-2 !pe-9"
                        >
                            @foreach ($filters as $value => $label)
                                <option value="{{ $value }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif

                @if (method_exists($this, 'getFiltersSchema'))
                    <x-filament::dropdown
                        placement="bottom-end"
                        shift
                        width="xs"
                        class="fi-wi-chart-filter"
                    >
                        <x-slot name="trigger">
                            {{ $this->getFiltersTriggerAction() }}
                        </x-slot>

                        <div class="fi-wi-chart-filter-content">
                            {{ $this->getFiltersSchema() }}

                            @if (method_exists($this, 'hasDeferredFilters') && $this->hasDeferredFilters())
                                <div
                                    class="fi-wi-chart-filter-content-actions-ctn"
                                >
                                    {{ $this->getFiltersApplyAction() }}

                                    {{ $this->getFiltersResetAction() }}
                                </div>
                            @endif
                        </div>
                    </x-filament::dropdown>
                @endif
            </x-slot>
        @endif

        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
        >
            <div
                wire:key="wolturnus-chart-grid-{{ $wolturnusTable['year'] }}"
                class="overflow-x-auto overflow-y-hidden rounded-lg border border-gray-200 [scrollbar-gutter:stable] dark:border-white/10"
            >
                <div
                    class="grid min-w-[52rem] grid-cols-[minmax(10rem,14rem)_repeat(12,minmax(0,1fr))] text-sm text-gray-950 dark:text-white"
                    role="group"
                    aria-label="{{ __('Wolturnus leveringen per maand en type') }}"
                >
                    {{-- Grafiek alleen boven de 12 maandkolommen (geen dubbele x-as-labels) --}}
                    <div
                        class="col-start-1 row-start-1 border-b border-gray-200 dark:border-white/10"
                        aria-hidden="true"
                    ></div>

                    <div
                        class="col-span-12 col-start-2 row-start-1 min-h-0 min-w-0 overflow-hidden border-b border-gray-200 p-0 dark:border-white/10"
                    >
                        <div
                            x-load
                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('wolturnus-chart', 'app') }}"
                            wire:ignore
                            data-chart-type="{{ $type }}"
                            x-data="chart({
                                        cachedData: @js($this->getCachedData()),
                                        maxHeight: @js($maxHeight = $this->getMaxHeight()),
                                        options: @js($this->getOptions()),
                                        type: @js($type),
                                    })"
                            {{
                                (new ComponentAttributeBag)
                                    ->color(ChartWidgetComponent::class, $color)
                                    ->class([
                                        'fi-wi-chart-canvas-ctn',
                                        'fi-wi-chart-canvas-ctn-no-aspect-ratio' => filled($maxHeight),
                                        'max-w-full overflow-hidden',
                                    ])
                            }}
                        >
                            <canvas
                                x-ref="canvas"
                                class="block w-full max-w-full"
                                width="1000"
                                height="400"
                                @if ($maxHeight)
                                    style="height: auto !important; max-height: {{ $maxHeight }}; width: 100% !important"
                                @endif
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

                    <div
                        class="col-start-1 row-start-2 bg-gray-50 px-3 py-2 text-start text-xs font-semibold dark:bg-white/5 sm:text-sm"
                    >
                        {{ __('Type') }}
                    </div>
                    @foreach ($wolturnusTable['labels'] as $idx => $monthLabel)
                        <div
                            class="row-start-2 border-l border-gray-200 bg-gray-50 px-2 py-2 text-end text-xs font-semibold tabular-nums dark:border-white/10 dark:bg-white/5 sm:text-sm"
                            style="grid-column-start: {{ $idx + 2 }}"
                        >
                            {{ $monthLabel }}
                        </div>
                    @endforeach

                    @foreach ($wolturnusTable['series'] as $rowIdx => $row)
                        <div
                            class="col-start-1 border-t border-gray-200 px-3 py-2 text-start text-xs font-medium dark:border-white/10 sm:text-sm"
                            style="grid-row-start: {{ $rowIdx + 3 }}"
                        >
                            {{ $row['label'] }}
                        </div>
                        @foreach ($row['values'] as $colIdx => $value)
                            <div
                                class="border-l border-t border-gray-200 px-2 py-2 text-end text-xs tabular-nums dark:border-white/10 sm:text-sm"
                                style="grid-column-start: {{ $colIdx + 2 }}; grid-row-start: {{ $rowIdx + 3 }}"
                            >
                                {{ $value }}
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

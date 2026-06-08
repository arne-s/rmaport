@php
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\Support\Js;
    use Illuminate\View\ComponentAttributeBag;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        <x-slot name="afterHeader">
                <div
                    class="flex flex-wrap items-end gap-x-3 gap-y-2"
                    wire:key="units-per-chair-year-filters-{{ $this->yearLeft }}-{{ $this->yearRight }}"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="fi-wi-chart-filter-label text-xs font-medium text-gray-600 dark:text-gray-400">
                            Jaar (links)
                        </span>
                        <x-filament::input.wrapper
                            class="fi-wi-chart-filter w-auto max-w-[8.5rem] shrink-0"
                        >
                            <x-filament::input.select
                                wire:model.live="yearLeft"
                                class="!ps-2 !pe-9"
                            >
                                <option value="">—</option>
                                @foreach ($this->getYearSelectOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="fi-wi-chart-filter-label text-xs font-medium text-gray-600 dark:text-gray-400">
                            Jaar (rechts)
                        </span>
                        <x-filament::input.wrapper
                            class="fi-wi-chart-filter w-auto max-w-[8.5rem] shrink-0"
                        >
                            <x-filament::input.select
                                wire:model.live="yearRight"
                                class="!ps-2 !pe-9"
                            >
                                <option value="">—</option>
                                @foreach ($this->getYearSelectOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <span class="fi-wi-chart-filter-label text-xs font-medium text-gray-600 dark:text-gray-400">
                            Trendlijn
                        </span>
                        <x-filament::input.wrapper
                            class="fi-wi-chart-filter w-auto max-w-[min(100%,14rem)] shrink-0"
                        >
                            <x-filament::input.select
                                wire:model.live="trendChairTypeKey"
                                class="!ps-2 !pe-9"
                            >
                                @foreach ($this->getTrendChairTypeOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                </div>

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

        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
        >
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
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
                        ])
                }}
            >
                <canvas
                    x-ref="canvas"
                    @if ($maxHeight)
                        style="max-height: {{ $maxHeight }}"
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

            @if (count($chairLegend = $this->getChairTypeLegendItems()) > 0)
                <div
                    class="mt-3 max-h-24 overflow-y-auto flex flex-wrap gap-x-4 gap-y-2 px-0.5"
                    wire:key="units-per-chair-type-legend"
                >
                    @foreach ($chairLegend as $item)
                        <button
                            type="button"
                            wire:click="toggleChairTypeLegend({{ Js::from($item['key']) }})"
                            @class([
                                'inline-flex cursor-pointer items-center gap-2 rounded-md border border-transparent px-2 py-1 text-start text-sm transition',
                                'text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5' => $item['visible'],
                                'text-gray-400 line-through opacity-70 hover:bg-gray-50/80 dark:text-gray-500 dark:hover:bg-white/5' => ! $item['visible'],
                            ])
                        >
                            <span
                                class="inline-block size-3 shrink-0 rounded-sm border"
                                style="background-color: {{ $item['fill'] }}; border-color: {{ $item['stroke'] }}"
                            ></span>
                            <span>{{ $item['label'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

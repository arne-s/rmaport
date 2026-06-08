@php
    $heading = $this->getHeading();
    $filters = $this->getFilters();
@endphp

<x-filament-widgets::widget class="filament-widgets-chart-widget">
    <div {!! ($pollingInterval = $this->getPollingInterval()) ? "wire:poll.{$pollingInterval}" : '' !!}>
        <x-filament::stats :columns="$this->getColumns()">
            <x-filament::card>

                <div class="widgetContentBlock text-xl font-bold">
                    <div class="titleBlock">
                        @if(!empty($subTitle))
                            <span class="labelWidget">{{ $subTitle }}</span>
                        @endif
                        {!!$title !!}
                    </div>

                    <div class="left" style="">
                        @if(isset($value))
                            <div class="valueBlock text-3xl">
                                {{ $value }}
                            </div>
                        @endif

                        @if (isset($description))
                            <div @class([
                                'flex items-center space-x-1 rtl:space-x-reverse text-sm font-medium',
                                match ($descriptionColor) {
                                    'danger' => 'text-danger-600',
                                    'primary' => 'text-primary-600',
                                    'success' => 'text-success-600',
                                    'warning' => 'text-warning-600',
                                    default => 'text-gray-600',
                                },
                            ])>
                                @if ($descriptionIcon && $descriptionIconPosition === 'before')
                                    <x-dynamic-component :component="$descriptionIcon" class="w-4 h-4"/>
                                @endif

                                <span>{{ $description }}</span>

                                @if ($descriptionIcon && $descriptionIconPosition === 'after')
                                    <x-dynamic-component :component="$descriptionIcon" class="w-4 h-4"/>
                                @endif
                            </div>
                        @endif

                        @if ($descriptionIcon && $descriptionIconPosition === 'before')
                            <x-dynamic-component :component="$descriptionIcon" class="w-4 h-4"/>
                        @endif


                    </div>
                </div>

                <div class="flex justify-between inner-wrapper">


                </div>

            </x-filament::card>


        </x-filament::stats>
    </div>


</x-filament::widget>

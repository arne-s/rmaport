@php
    $columns = $this->getColumns();
    $pollingInterval = $this->getPollingInterval();

    $heading = $this->getHeading();
    $description = $this->getDescription();
    $hasHeading = filled($heading);
    $hasDescription = filled($description);
@endphp

<x-filament-widgets::widget
    :attributes="
        (new \Illuminate\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
            ->class([
                'fi-wi-stats-overview',
                'fi-production-overview-widget',
            ])
    "
>
    <section class="quick-links-widget__heading status">
        <strong>RMA</strong>
        <p>Aantallen per status</p>
    </section>
    {{ $this->content }}
</x-filament-widgets::widget>

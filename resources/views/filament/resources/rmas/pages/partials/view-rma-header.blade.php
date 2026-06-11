@php
    /** @var \App\Filament\Resources\RmaResource\Pages\ViewRma $this */
@endphp
<div class="header view-rma-header">
    <div class="breadcrumb">
        <div class="backTo">
            <a href="{{ route('filament.app.resources.rmas.index') }}">
                @svgImg('img/icons/chevron-left.svg')
                <span>Terug naar Retouren-overzicht</span>
            </a>
        </div>
    </div>

    <div class="title-container view-event">
        <div class="title-container__title-group view-order-event">
            @php
                $headingBody = preg_replace('/^RMA:\s*/', '', $this->getRmaViewHeading());
            @endphp
            <h2 class="title"><span class="rma-title-prefix">RMA:</span> {{ $headingBody }}</h2>
            <button
                type="button"
                class="order-events-link order-events-link--icon"
                title="Historie"
                aria-label="Historie"
                x-on:click="$dispatch('open-modal', { id: 'rma-events' })">
                <svg class="order-events-link__icon h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                    fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd"
                        d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25ZM12.75 6a.75.75 0 0 0-1.5 0v6c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-3.75V6Z"
                        clip-rule="evenodd"></path>
                </svg>
            </button>

            <div class="fi-input-wrp fi-fo-select fi-fo-select-native order-status-select-wrp">
                <div class="fi-input-wrp-content-ctn">
                    <select
                        wire:model.live="rmaStatus"
                        class="fi-select-input"
                        id="rma-status-select"
                        aria-label="RMA status">
                        @foreach ($this->getRmaStatusDropdownOptions() as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="title-container__right">
            @foreach($this->getHeaderActionsForView() as $action)
                {{ $action }}
            @endforeach
        </div>
    </div>

    <x-filament::tabs>
        <x-filament::tabs.item
            alpine-active="activeTab === 'general'"
            x-on:click="activeTab = 'general'">
            Algemeen
        </x-filament::tabs.item>
        <x-filament::tabs.item
            alpine-active="activeTab === 'notes'"
            x-on:click="activeTab = 'notes'">
            Notities
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>

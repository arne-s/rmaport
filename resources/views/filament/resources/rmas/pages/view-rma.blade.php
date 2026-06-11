@php
    use App\Filament\Resources\RmaResource\Pages\ViewRma;
    use App\Filament\Resources\RmaResource\Widgets\RmaNotesWidget;
    use App\Models\Rma;

    /** @var ViewRma $this */
    /** @var Rma $record */
    $record = $this->record;
@endphp

<x-filament-panels::page>
    <div
        class="w-full rma-view-root"
        x-data="{ activeTab: @entangle('rmaViewTab').live }"
    >
        @include('filament.resources.rmas.pages.partials.view-rma-header')

        <div x-show="activeTab === 'general'" x-cloak>
            @include('filament.resources.rmas.pages.general-tab', ['record' => $record])
        </div>

        <div x-show="activeTab === 'notes'" x-cloak>
            <main class="notesTab">
                @livewire(RmaNotesWidget::class, ['record' => $record], key('rma-notes-'.$record->getKey()))
            </main>
        </div>
    </div>

    <x-filament::modal class="openDocumentModal order-events-modal rma-events-modal" id="rma-events">
        <div
            class="contentContainer order-events-modal__content"
            x-data="{ isOpen: false, activeTab: 'status' }"
            x-on:open-modal.window="if ($event.detail.id === 'rma-events') { isOpen = true; activeTab = $event.detail.tab || 'status' }"
            x-on:close="isOpen = false"
            x-show="isOpen"
            x-cloak
        >
            <div class="tabs order-events-modal__tabs" role="tablist" aria-label="Status, Historie">
                <button
                    class="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'status' ? 'true' : 'false'"
                    x-on:click="activeTab = 'status'"
                >
                    Status
                </button>
                <button
                    class="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'historie' ? 'true' : 'false'"
                    x-on:click="activeTab = 'historie'"
                >
                    Historie
                </button>
            </div>

            <div x-show="activeTab === 'status'" class="order-events-modal__panel">
                @include('filament.resources.rmas.rma-status-overview', [
                    'timeline' => $this->getRmaStatusTimeline(),
                ])
            </div>

            <div x-show="activeTab === 'historie'" class="order-events-modal__panel">
                @include('filament.resources.rmas.partials.rma-events-table', [
                    'rmaEvents' => $this->getRmaEventsForHistory(),
                ])
            </div>
        </div>
    </x-filament::modal>
</x-filament-panels::page>

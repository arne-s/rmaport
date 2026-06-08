{{--
    Shared Filament edit page layout with record locking.
    Use via ManagesRecordLock::EDIT_PAGE_VIEW or @include from a thin page view.

    @var \Filament\Resources\Pages\EditRecord&\App\Filament\Concerns\ManagesRecordLock $this
--}}
<x-filament-panels::page>
    @if ($this->isRecordLockBlocked())
        <x-filament.record-lock-blocked :details="$this->recordLockBlockedDetails" />
    @else
        @if ($this->usesRecordLock())
            @include('filament.partials.record-lock-poll')
        @endif

        {{ $this->content }}

        @if ($recordLockAdditional = $this->getRecordLockAdditionalBlade())
            {!! $recordLockAdditional !!}
        @endif
    @endif
</x-filament-panels::page>

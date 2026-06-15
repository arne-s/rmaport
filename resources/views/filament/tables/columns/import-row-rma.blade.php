@php
    use App\Filament\Resources\RmaResource;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;

    /** @var \App\Models\ImportRow $record */
@endphp

@if ($record->rma)
    <a
        href="{{ RmaResource::getUrl('view', ['record' => $record->rma]) }}"
        class="import-row-rma-link"
        onclick="event.stopPropagation()"
    >
        {{ $record->rma->uid }}
    </a>
@else
    <div class="fi-ta-actions fi-align-center" onclick="event.stopPropagation()">
        <button
            type="button"
            class="fi-btn fi-ac-btn-action fi-color-gray"
            wire:click.prevent.stop="mountTableAction('createRmaFromImportRow', '{{ $record->getKey() }}')"
        >
            {{
                \Filament\Support\generate_icon_html(
                    Heroicon::PlusCircle,
                    size: IconSize::ExtraSmall,
                )
            }}
            <span class="fi-ac-btn-action-label">Aanmaken</span>
        </button>
    </div>
@endif

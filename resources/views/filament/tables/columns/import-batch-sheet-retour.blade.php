@php
    use App\Support\FormatDisplayDate;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;

    /** @var \App\Models\ImportBatch $record */
@endphp

@if ($record->export)
    <div class="numberPlusDate" onclick="event.stopPropagation()">
        <div class="linksDocuments">
            <span class="value">{{ FormatDisplayDate::longDateTime($record->export->created_at) }}</span>
            <a
                class="downloadDocument"
                href="{{ route('import-exports.download', $record->export) }}"
                title="Sheet retour downloaden"
            ></a>
        </div>
    </div>
@else
    <div class="fi-ta-actions fi-align-center" onclick="event.stopPropagation()">
        <button
            type="button"
            class="fi-btn fi-ac-btn-action fi-color-gray"
            wire:click.prevent.stop="mountTableAction('sendExport', '{{ $record->getKey() }}')"
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

@php
    /** @var string $tab Must match {@see \App\Filament\Resources\OrderResource\Pages\Traits\StatusTrait::$orderViewTab} value. */
    /** @var string $idPrefix Unique HTML id prefix for this tab's inputs. */
@endphp

@if ($this->orderViewTab === $tab)
    <div class="fitting-note-field-row">
        <div class="fitting-note-field-label-col">
            <label for="{{ $idPrefix }}AdvisorDealerName" class="fi-fo-field-label-ctn">
                <span class="fi-fo-field-label-content text-sm font-medium">Adviseur dealer (naam)</span>
            </label>
        </div>
        <div class="fitting-note-field-content-col">
            <div class="fi-input-wrp">
                <input type="text" id="{{ $idPrefix }}AdvisorDealerName"
                       wire:model.live="fittingNoteAdvisorDealerName" maxlength="255"
                       class="fi-input block w-full">
            </div>
        </div>
    </div>
    <div class="fitting-note-field-row">
        <div class="fitting-note-field-label-col">
            <label for="{{ $idPrefix }}AdvisorDealerMobile" class="fi-fo-field-label-ctn">
                <span class="fi-fo-field-label-content text-sm font-medium">Adviseur dealer (mobiel)</span>
            </label>
        </div>
        <div class="fitting-note-field-content-col">
            <div class="fi-input-wrp">
                <input type="tel" id="{{ $idPrefix }}AdvisorDealerMobile"
                       wire:model.live="fittingNoteAdvisorDealerMobile" maxlength="255"
                       class="fi-input block w-full">
            </div>
        </div>
    </div>
    <div class="fitting-note-field-row" @if(filled($emailRowStyle ?? null)) style="{{ $emailRowStyle }}" @endif>
        <div class="fitting-note-field-label-col">
            <label for="{{ $idPrefix }}AdvisorDealerEmail" class="fi-fo-field-label-ctn">
                <span class="fi-fo-field-label-content text-sm font-medium">Adviseur dealer (e-mail)</span>
            </label>
        </div>
        <div class="fitting-note-field-content-col">
            <div class="fi-input-wrp">
                <input type="email" id="{{ $idPrefix }}AdvisorDealerEmail"
                       wire:model.live="fittingNoteAdvisorDealerEmail" maxlength="255"
                       class="fi-input block w-full">
            </div>
        </div>
    </div>
@endif

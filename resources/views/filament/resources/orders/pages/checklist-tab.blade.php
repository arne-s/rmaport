@php
    use App\Models\Order\Main;
    use App\Services\ChecklistService;

    /** @var Main $record */
    $main = $record->getMain() ?? $record;
    $checklistSummary = app(ChecklistService::class)->getMainSummaryData($main);
@endphp

<main class="checklistTab">
    <section class="card checklistTab__card">
        <div class="checklistTab__top">
            <h3 class="card__title">Controlelijst</h3>
            <img
                class="checklistTab__logo"
                src="{{ asset('img/logo.svg') }}"
                alt="RD Mobility"
            >
        </div>

        <x-main-summary
            :customer-name="$checklistSummary['customerName']"
            :unit-name="$checklistSummary['unitName']"
            :advisor-name="$checklistSummary['advisorName']"
            :rows="$checklistSummary['summaryRows']"
        >
            <x-slot:aside>
                <div class="checklistTab__extra-note">
                    <label for="checklistExtraNote" class="fi-fo-field-label-ctn">
                        <span class="fi-fo-field-label-content text-sm font-medium">Extra opmerking:</span>
                    </label>
                    <div class="fi-input-wrp checklistTab__extra-note-input">
                        <textarea
                            id="checklistExtraNote"
                            wire:model.defer="checklistExtraNote"
                            maxlength="65535"
                            class="fi-input block w-full checklist-comments-input"
                        ></textarea>
                    </div>
                </div>
            </x-slot:aside>
        </x-main-summary>
    </section>
</main>

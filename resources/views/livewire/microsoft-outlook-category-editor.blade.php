<div class="microsoft-outlook-category-editor">
    <button
        type="button"
        wire:click="openModal"
        class="fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray fi-btn-outlined inline-grid"
    >
        <span class="fi-btn-label">Categorieën instellen</span>
    </button>

    @if ($showModal)
        <template x-teleport="body">
            <div
                class="fi-modal-window microsoft-outlook-category-editor__overlay fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                role="dialog"
                aria-modal="true"
                wire:click="closeModal"
                wire:keydown.escape="closeModal"
            >
                <div
                    class="microsoft-outlook-category-editor__dialog relative flex max-h-[90vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl"
                    wire:click.stop
                >
                    <div class="shrink-0 border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">Categorieën instellen</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            Categorieën worden opgeslagen in Outlook. Bestaande namen kunnen niet worden gewijzigd (beperking van Outlook).
                        </p>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                        @if ($tokenExpiredMessage)
                            <p class="mb-4 text-sm text-warning-600">{{ $tokenExpiredMessage }}</p>
                        @endif

                        @if ($errorMessage)
                            <p class="mb-4 text-sm text-danger-600">{{ $errorMessage }}</p>
                        @endif

                        <div class="microsoft-outlook-category-editor__rows flex flex-col gap-4">
                            @foreach ($rows as $index => $row)
                                @if (! $row['removed'])
                                    <div
                                        wire:key="outlook-cat-row-{{ $row['key'] }}"
                                        class="microsoft-outlook-category-editor__row rounded-lg border border-gray-200 p-4"
                                    >
                                        <div class="flex flex-wrap items-start gap-4">
                                            <div class="min-w-[12rem] flex-1">
                                                <label class="mb-1 block text-xs font-medium text-gray-500">Naam</label>
                                                @if ($row['is_new'])
                                                    <input
                                                        type="text"
                                                        wire:model.live="rows.{{ $index }}.displayName"
                                                        class="microsoft-outlook-settings__input w-full"
                                                        placeholder="Categorienaam"
                                                        autocomplete="off"
                                                    />
                                                @else
                                                    <input
                                                        type="text"
                                                        value="{{ $row['displayName'] }}"
                                                        readonly
                                                        class="microsoft-outlook-settings__input w-full bg-gray-50 text-gray-700"
                                                    />
                                                @endif
                                            </div>

                                            <div class="flex-1 min-w-[14rem]">
                                                <label class="mb-1 block text-xs font-medium text-gray-500">Kleur</label>
                                                <div class="microsoft-outlook-category-editor__swatches flex flex-wrap gap-1.5">
                                                    @foreach ($this->presetColorKeys() as $preset)
                                                        <button
                                                            type="button"
                                                            wire:click="$set('rows.{{ $index }}.color', @js($preset))"
                                                            title="{{ $preset }}"
                                                            @class([
                                                                'microsoft-outlook-category-editor__swatch h-7 w-7 rounded-md border-2 transition',
                                                                'border-primary-500 ring-2 ring-primary-200' => $row['color'] === $preset,
                                                                'border-transparent hover:border-gray-300' => $row['color'] !== $preset,
                                                            ])
                                                            style="background-color: {{ $this->presetHex($preset) }};"
                                                        ></button>
                                                    @endforeach
                                                </div>
                                            </div>

                                            <div class="flex shrink-0 items-end self-end">
                                                @if ($row['can_delete'])
                                                    <button
                                                        type="button"
                                                        wire:click="markRowRemoved(@js($row['key']))"
                                                        class="fi-btn fi-btn-size-sm fi-btn-color-danger fi-color-danger fi-btn-outlined inline-grid"
                                                    >
                                                        <span class="fi-btn-label">Verwijderen</span>
                                                    </button>
                                                @else
                                                    <span
                                                        class="text-xs text-gray-500"
                                                        title="Er {{ $row['linked_user_count'] === 1 ? 'is' : 'zijn' }} {{ $row['linked_user_count'] }} medewerker(s) aan deze categorie gekoppeld."
                                                    >
                                                        Gekoppeld aan {{ $row['linked_user_count'] }} {{ $row['linked_user_count'] === 1 ? 'gebruiker' : 'gebruikers' }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <button
                            type="button"
                            wire:click="addRow"
                            class="fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray fi-btn-outlined mt-4 inline-grid"
                        >
                            <span class="fi-btn-label">Categorie toevoegen</span>
                        </button>
                    </div>

                    <div class="flex shrink-0 justify-end gap-3 border-t border-gray-200 px-6 py-4">
                        <button
                            type="button"
                            wire:click="closeModal"
                            class="fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray inline-grid"
                        >
                            <span class="fi-btn-label">Annuleren</span>
                        </button>
                        <button
                            type="button"
                            wire:click="save"
                            wire:loading.attr="disabled"
                            class="fi-btn fi-btn-size-sm fi-btn-color-primary fi-color-primary inline-grid"
                        >
                            <span class="fi-btn-label" wire:loading.remove wire:target="save">Opslaan</span>
                            <span class="fi-btn-label" wire:loading wire:target="save">Bezig…</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>

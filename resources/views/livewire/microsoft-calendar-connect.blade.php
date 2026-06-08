<div class="microsoft-calendar-connect microsoft-outlook-settings fi-fo-field fi-fo-field-has-inline-label">
    <div class="fi-fo-field-label-col fi-vertical-align-center">
        <div class="fi-fo-field-label-ctn">
            <div class="fi-fo-field-label">
                <span class="fi-fo-field-label-content">Outlook: afspraken</span>
            </div>
        </div>
    </div>

    <div class="fi-fo-field-content-col microsoft-outlook-settings__fields">
        @if ($token)
            <div class="flex flex-wrap items-center gap-3 pb-1">
                <div class="flex items-center gap-2 text-sm text-success-700">
                    <x-heroicon-s-check-circle class="size-5 shrink-0" />
                    <span>Gekoppeld{{ $token->microsoft_email ? ' als ' . $token->microsoft_email : '' }}</span>
                </div>

                <button
                    type="button"
                    wire:click="disconnect"
                    wire:confirm="Weet je zeker dat je de Outlook-agenda wilt ontkoppelen?"
                    class="fi-btn fi-btn-size-sm fi-btn-color-danger fi-color-danger fi-btn-outlined inline-grid"
                >
                    <span class="fi-btn-label">Ontkoppelen</span>
                </button>
            </div>

            @if (count($calendars) > 0)
                <x-outlook-settings-field label="Agenda" :for="'calendar-id-' . $tokenId">
                    <select
                        id="calendar-id-{{ $tokenId }}"
                        wire:model="selectedCalendarId"
                        wire:change="saveCalendar"
                        class="microsoft-outlook-settings__select"
                    >
                        <option value="">Selecteer een optie</option>
                        @foreach ($calendars as $calendar)
                            <option value="{{ $calendar['id'] }}">
                                {{ $calendar['name'] }}{{ $calendar['isDefault'] ? ' (standaard)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </x-outlook-settings-field>
            @endif

            <x-outlook-settings-field label="Koppel aan rol" :for="'calendar-role-' . $tokenId">
                <select
                    id="calendar-role-{{ $tokenId }}"
                    wire:model.live="roleId"
                    class="microsoft-outlook-settings__select"
                >
                    <option value="">Selecteer een optie</option>
                    @foreach ($roleOptions as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-outlook-settings-field>

            <x-outlook-settings-field label="Weergavenaam" :for="'calendar-display-name-' . $tokenId">
                <input
                    id="calendar-display-name-{{ $tokenId }}"
                    type="text"
                    wire:model.live.debounce.500ms="calendarDisplayName"
                    placeholder="Optioneel"
                    autocomplete="off"
                    @keydown.enter.prevent
                    class="microsoft-outlook-settings__input"
                />
            </x-outlook-settings-field>

            <x-outlook-settings-field label="Algemene categorie" :for="'calendar-general-category-' . $tokenId">
                <select
                    id="calendar-general-category-{{ $tokenId }}"
                    wire:model.live="selectedGeneralCategoryName"
                    class="microsoft-outlook-settings__select"
                >
                    <option value="">Selecteer een optie (optioneel)</option>
                    @if (filled($selectedGeneralCategoryName) && ! array_key_exists($selectedGeneralCategoryName, $generalCategoryOptions))
                        <option value="{{ $selectedGeneralCategoryName }}">{{ $selectedGeneralCategoryName }}</option>
                    @endif
                    @foreach ($generalCategoryOptions as $name => $label)
                        <option value="{{ $name }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-outlook-settings-field>

            @if ($roleConflictMessage)
                <p class="text-sm text-danger-600">{{ $roleConflictMessage }}</p>
            @endif

            @livewire(
                \App\Http\Livewire\MicrosoftCategoryMappings::class,
                ['tokenId' => $tokenId],
                key('microsoft-category-mappings-' . $tokenId)
            )
        @else
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2 text-sm text-gray-500">
                    <x-heroicon-o-x-circle class="size-5 shrink-0" />
                    <span>Niet gekoppeld</span>
                </div>

                <a
                    href="{{ route('microsoft.connect') }}"
                    class="fi-btn fi-btn-size-sm fi-btn-color-primary fi-color-primary inline-grid"
                >
                    <span class="fi-btn-label">Koppel Outlook-agenda</span>
                </a>
            </div>
        @endif

        @if (session('success'))
            <p class="text-sm text-success-600">{{ session('success') }}</p>
        @endif

        @if (session('error'))
            <p class="text-sm text-danger-600">{{ session('error') }}</p>
        @endif
    </div>
</div>

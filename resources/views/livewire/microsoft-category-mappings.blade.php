<div class="microsoft-category-mappings microsoft-outlook-settings">
    @if ($hasToken)
        @if ($statusMessage)
            <p class="mb-3 text-sm text-warning-600">{{ $statusMessage }}</p>
        @endif

        @if ($usageMessage)
            <p class="mb-3 text-sm text-gray-500">{{ $usageMessage }}</p>
        @endif

        @if (! $statusMessage)
            <div class="microsoft-outlook-settings__actions flex items-center gap-3 pb-3">
                @livewire(
                    \App\Http\Livewire\MicrosoftOutlookCategoryEditor::class,
                    ['tokenId' => $tokenId],
                    key('microsoft-outlook-category-editor-' . $tokenId)
                )
            </div>
        @endif

        @if (! $usageMessage && count($users) > 0 && count($categories) > 0)
            <div class="microsoft-outlook-settings__fields microsoft-outlook-settings__fields--category-mappings">
                <div class="microsoft-outlook-settings__section-heading">
                    Categorie per gebruiker
                </div>

                @foreach ($users as $user)
                    <div wire:key="category-mapping-user-{{ $user['id'] }}">
                        <x-outlook-settings-field
                            :label="$user['name']"
                            :for="'outlook-category-' . $tokenId . '-' . $user['id']"
                        >
                            <x-outlook-category-select
                                :user-id="$user['id']"
                                :token-id="$tokenId"
                                :selected="$userMappings[$user['id']] ?? null"
                                :category-options="$this->getAvailableCategoryOptionsForUser($user['id'])"
                                :all-category-options="$categoryOptions"
                            />
                        </x-outlook-settings-field>
                    </div>
                @endforeach

                <div class="microsoft-outlook-settings__actions flex items-center gap-3 pt-1">
                    <button
                        type="button"
                        wire:click="save"
                        class="fi-btn fi-btn-size-sm fi-btn-color-primary fi-color-primary inline-grid"
                    >
                        <span class="fi-btn-label">Koppelingen opslaan</span>
                    </button>

                    @if ($saved)
                        <span class="text-sm text-success-600">Opgeslagen!</span>
                    @endif
                </div>
            </div>
        @elseif (! $usageMessage && count($users) === 0)
            <p class="text-sm text-gray-500">Geen gebruikers gevonden voor de geselecteerde rol.</p>
        @elseif (! $usageMessage && count($categories) === 0)
            <p class="text-sm text-gray-500">Geen categorieën gevonden. Gebruik &quot;Categorieën instellen&quot; om categorieën toe te voegen.</p>
        @endif
    @endif
</div>

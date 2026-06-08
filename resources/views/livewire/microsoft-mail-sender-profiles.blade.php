<div class="microsoft-mail-sender-profiles">
    <div class="fi-fo-field fi-fo-field-has-inline-label">
        <div class="fi-fo-field-label-col fi-vertical-align-start pt-1">
            <div class="fi-fo-field-label-ctn">
                <div class="fi-fo-field-label">
                    <span class="fi-fo-field-label-content"></span>
                </div>
            </div>
        </div>

        <div class="fi-fo-field-content-col">
            @if (empty($profiles))
                <p class="text-sm text-gray-500">Geen verzendprofielen aangemaakt.</p>
            @else
                <div class="space-y-2">
                    @foreach ($profiles as $profile)
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 text-sm text-gray-700" style="width: 200px">{{ $profile['name'] }}</span>
                            <select
                                wire:model="mappings.{{ $profile['id'] }}"
                                class="fi-select-input block w-full max-w-xs rounded-lg border-0 py-1.5 shadow-sm ring-1 ring-gray-300 focus:ring-2 focus:ring-primary-600 sm:text-sm"
                            >
                                <option value="">— Geen account —</option>
                                @foreach ($tokens as $token)
                                    <option value="{{ $token['id'] }}">{{ $token['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex items-center gap-3">
                    <button
                        type="button"
                        wire:click="save"
                        class="fi-btn fi-btn-size-sm fi-btn-color-primary fi-color-primary inline-grid"
                    >
                        <span class="fi-btn-label">Opslaan</span>
                    </button>

                    @if ($saved)
                        <span class="text-sm text-success-600">Opgeslagen!</span>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

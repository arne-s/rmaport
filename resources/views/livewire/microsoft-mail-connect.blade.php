<div class="microsoft-mail-connect fi-fo-field fi-fo-field-has-inline-label">
    <div class="fi-fo-field-label-col fi-vertical-align-center">
        <div class="fi-fo-field-label-ctn">
            <div class="fi-fo-field-label">
                <span class="fi-fo-field-label-content">Outlook e-mail</span>
            </div>
        </div>
    </div>

    <div class="fi-fo-field-content-col space-y-3">
        @if ($token)
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2 text-sm text-success-700">
                    <x-heroicon-s-check-circle class="size-5 shrink-0" />
                    <span>
                        Gekoppeld{{ $token->microsoft_email ? ' als ' . $token->microsoft_email : '' }}
                        @if ($token->is_default)
                            <span class="ml-1 text-xs font-semibold text-primary-600">(standaard)</span>
                        @endif
                    </span>
                </div>

                @if (!$token->is_default)
                    <button
                        type="button"
                        wire:click="setDefault"
                        class="fi-btn fi-btn-size-sm fi-btn-color-gray fi-color-gray fi-btn-outlined inline-grid"
                    >
                        <span class="fi-btn-label">Gebruik als standaard</span>
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="unsetDefault"
                        wire:confirm="Weet je zeker dat je dit account niet meer als standaard wilt gebruiken? Systeem-e-mails worden dan alleen gelogd."
                        class="fi-btn fi-btn-size-sm fi-btn-color-warning fi-color-warning fi-btn-outlined inline-grid"
                    >
                        <span class="fi-btn-label">Verwijder als standaard</span>
                    </button>
                @endif

                <button
                    type="button"
                    wire:click="disconnect"
                    wire:confirm="Weet je zeker dat je dit Outlook e-mailaccount wilt ontkoppelen?"
                    class="fi-btn fi-btn-size-sm fi-btn-color-danger fi-color-danger fi-btn-outlined inline-grid"
                >
                    <span class="fi-btn-label">Ontkoppelen</span>
                </button>
            </div>
        @else
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 text-sm text-gray-500">
                    <x-heroicon-o-x-circle class="size-5 shrink-0" />
                    <span>Niet gekoppeld</span>
                </div>
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

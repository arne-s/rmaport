@php
    $statePath   = $getStatePath();
    $placeholder = $getPlaceholder() ?? 'Selecteer of typ een e-mailadres';
    $options      = $getOptions();
    $lockedValues = $getLockedValues();
    $id           = $getId();
    $isDisabled   = $isDisabled();
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            options: @js($options),
            lockedValues: @js($lockedValues),
            search: '',
            open: false,
            focusedIndex: -1,

            init() {
                if (!this.state) {
                    this.state = [];
                }
                this.lockedValues.forEach((key) => {
                    if (!this.state.includes(key)) {
                        this.state = [...this.state, key];
                    }
                });
            },

            isLocked(value) {
                return this.lockedValues.includes(value);
            },

            get items() {
                const s = this.search.toLowerCase();
                return Object.entries(this.options).filter(([key, label]) =>
                    !(this.state ?? []).includes(key) &&
                    (s === '' || label.toLowerCase().includes(s))
                );
            },

            get canAddEmail() {
                const e = this.search.trim();
                return e.length > 0
                    && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)
                    && !(this.state ?? []).includes(e)
                    && !this.items.some(([k]) => k === e);
            },

            selectOption(key) {
                if (!this.state) this.state = [];
                if (!this.state.includes(key)) this.state = [...this.state, key];
                this.reset();
            },

            addFreeEmail() {
                const email = this.search.trim();
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    if (!this.state) this.state = [];
                    if (!this.state.includes(email)) this.state = [...this.state, email];
                    this.reset();
                }
            },

            removeTag(value) {
                if (this.isLocked(value)) {
                    return;
                }
                this.state = (this.state ?? []).filter(v => v !== value);
                this.$nextTick(() => this.$refs.input?.focus());
            },

            reset() {
                this.search = '';
                this.open = false;
                this.focusedIndex = -1;
                this.$nextTick(() => this.$refs.input?.focus());
            },

            getLabel(value) {
                return this.options[value] ?? value;
            },

            onKeydown(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (this.focusedIndex >= 0 && this.focusedIndex < this.items.length) {
                        this.selectOption(this.items[this.focusedIndex][0]);
                    } else if (this.canAddEmail) {
                        this.addFreeEmail();
                    } else if (this.items.length === 1) {
                        this.selectOption(this.items[0][0]);
                    }
                } else if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.open = true;
                    this.focusedIndex = Math.min(this.focusedIndex + 1, this.items.length - 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.focusedIndex = Math.max(this.focusedIndex - 1, -1);
                } else if (event.key === 'Escape') {
                    this.open = false;
                    this.search = '';
                    this.focusedIndex = -1;
                } else if (event.key === 'Backspace' && this.search === '' && (this.state ?? []).length > 0) {
                    this.state = this.state.slice(0, -1);
                }
            },
        }"
        x-on:click.outside="open = false; focusedIndex = -1"
        class="email-recipient-select relative min-w-0 whitespace-normal"
    >
        {{-- Input wrapper — uses fi-input-wrp directly for correct border/ring styling --}}
        <div
            x-on:click="$refs.input?.focus()"
            @class([
                'fi-input-wrp cursor-text',
                'fi-invalid' => $errors->has($statePath),
                'fi-disabled' => $isDisabled,
            ])
        >
            <div class="fi-input-wrp-content-ctn" style="border: 1px solid #adadad; border-radius: 2px; padding: 0">
                <div class="email-recipient-select__tags flex min-h-9 flex-wrap items-center gap-1.5 px-3 py-1.5" style="padding: 2px; gap: 0">
                    {{-- Selected chips --}}
                    <template x-for="value in (state ?? [])" :key="value">
                        <x-filament::badge color="primary" class="email-recipient-select__chip max-w-full min-w-0" style="margin: 2px">
                            <span x-text="getLabel(value)"></span>
                            @unless ($isDisabled)
                                <template x-if="!isLocked(value)">
                                    <x-slot
                                        name="deleteButton"
                                        x-on:click.stop="removeTag(value)"
                                    ></x-slot>
                                </template>
                            @endunless
                        </x-filament::badge>
                    </template>

                    {{-- Text input --}}
                    @unless ($isDisabled)
                        <input
                            style="border: none"
                            x-ref="input"
                            type="text"
                            x-model="search"
                            x-on:input="open = true; focusedIndex = -1"
                            x-on:focus="open = true"
                            x-on:keydown="onKeydown($event)"
                            autocomplete="off"
                            data-1p-ignore
                            data-lpignore="true"
                            data-bwignore="true"
                            data-form-type="other"
                            id="{{ $id }}"
                            placeholder="{{ $placeholder }}"
                            class="email-recipient-select__input min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-gray-950 placeholder:text-gray-400 outline-hidden focus:ring-0 dark:text-white dark:placeholder:text-gray-500"
                        />
                    @endunless
                </div>
            </div>
        </div>

        {{-- Dropdown: min-width matches input, can grow wider for long labels --}}
        <div
            x-show="open && (items.length > 0 || canAddEmail)"
            x-cloak
            x-transition:enter="transition ease-out duration-75"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="email-recipient-select__dropdown absolute left-0 top-full z-[99999] mt-1 max-h-60 overflow-y-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <div
                x-show="canAddEmail"
                x-on:click="addFreeEmail()"
                class="email-recipient-select__dropdown-item flex cursor-pointer items-center gap-2 px-3 py-2 text-sm text-gray-900 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5"
            >
                <x-filament::icon icon="heroicon-m-plus-circle" class="h-4 w-4 shrink-0 text-primary-500" />
                <span>Toevoegen: <strong x-text="search.trim()"></strong></span>
            </div>

            <template x-for="([key, label], index) in items" :key="key">
                <div
                    x-on:click="selectOption(key)"
                    :class="focusedIndex === index ? 'bg-gray-50 dark:bg-white/5' : ''"
                    class="email-recipient-select__dropdown-item cursor-pointer select-none px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                    x-text="label"
                ></div>
            </template>
        </div>
    </div>
</x-dynamic-component>

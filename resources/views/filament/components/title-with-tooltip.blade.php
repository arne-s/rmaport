@props(['title', 'tooltip' => '', 'class' => ''])
<header class="fi-section-header" style="margin-left: -10px; margin-top: -10px; padding-bottom: 5px">
    <div class="fi-section-header-text-ctn">
        <h2 class="fi-section-header-heading {{ $class }}">
            {{ $title }}
            @if (!empty($tooltip))
                <button x-tooltip="{
                    content: '{!! $tooltip !!}',
                    theme: $store.theme,
                }" class="fi-color fi-color-primary fi-text-color-700 dark:fi-text-color-400 fi-link fi-size-sm  fi-ac-link-action text-gray-500 position-absolute" style="color: #000;" type="button" wire:loading.attr="disabled" >
                    <svg wire:loading.remove.delay.default="1" class="fi-icon fi-size-sm" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"></path>
                    </svg>                <svg fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" class="fi-icon fi-loading-indicator fi-size-sm" wire:loading.delay.default="" wire:target="mountAction('help', {}, JSON.parse('{\u0022recordKey\u0022:\u00221\u0022,\u0022schemaComponent\u0022:\u0022form.title\u0022}'))">
                        <path clip-rule="evenodd" d="M12 19C15.866 19 19 15.866 19 12C19 8.13401 15.866 5 12 5C8.13401 5 5 8.13401 5 12C5 15.866 8.13401 19 12 19ZM12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill-rule="evenodd" fill="currentColor" opacity="0.2"></path>
                        <path d="M2 12C2 6.47715 6.47715 2 12 2V5C8.13401 5 5 8.13401 5 12H2Z" fill="currentColor"></path>
                    </svg>
                </button>
            @endif
        </h2>
    </div>
</header>

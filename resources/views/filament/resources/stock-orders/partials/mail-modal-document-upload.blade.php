@php
    /** @var bool $hasAttachableDocuments */
    $hasAttachableDocuments = $hasAttachableDocuments ?? false;
@endphp
<div class="fi-fo-field-wrp" x-data>
    @if ($hasAttachableDocuments)
        <div class="-mt-[20px]">
            <button
                type="button"
                class="text-[11px] underline fi-link cursor-pointer text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                x-on:click="$refs.fileInput.click()"
            >
                (toevoegen)
            </button>
        </div>
    @else
        <div class="text-gray-600 dark:text-gray-400">
            <span class="inline-flex items-center gap-1 text-[11px]">
                Geen documenten geupload
                <button
                    type="button"
                    class="underline fi-link cursor-pointer text-primary-600 hover:text-primary-500 dark:text-primary-400"
                    x-on:click="$refs.fileInput.click()"
                >
                    (toevoegen)
                </button>
            </span>
        </div>
    @endif
    <input
        type="file"
        wire:model="documentFiles"
        multiple
        class="sr-only"
        x-ref="fileInput"
        accept="{{ config('documents.accept_attribute') }}"
    />
    <div wire:loading wire:target="documentFiles" class="text-xs text-gray-500 mt-1">
        Uploaden...
    </div>
</div>

<section
    class="note-pending-attachments"
    x-data="{
        isDragging: false,
        previewOpen: false,
        previewUrl: '',
        previewName: '',
        openPreview(url, name) {
            this.previewUrl = url;
            this.previewName = name || '';
            this.previewOpen = true;
        },
        closePreview() {
            this.previewOpen = false;
        },
        handleDrop(e) {
            e.preventDefault();
            this.isDragging = false;
            const input = $el.querySelector('input[type=file]');
            if (!input || !e.dataTransfer.files.length) return;
            const dt = new DataTransfer();
            for (const file of e.dataTransfer.files) dt.items.add(file);
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        handleDragover(e) {
            e.preventDefault();
            this.isDragging = true;
        },
        handleDragleave(e) {
            e.preventDefault();
            this.isDragging = false;
        },
    }"
    wire:key="note-pending-upload-{{ $bucket }}"
>
    <div class="docs-list">
        <div class="docs-list-inner">
            @foreach ($documents as $doc)
                <div class="doc" wire:key="pending-doc-{{ $bucket }}-{{ $doc['id'] }}">
                    <div class="doc__left">
                        <span class="icon-file" aria-hidden="true">
                            @svgImg('img/icons/document.svg')
                        </span>
                        <span class="doc__name">
                            @if(($doc['openable'] ?? false) && filled($doc['public_url'] ?? null))
                                <a
                                    href="#"
                                    x-on:click.prevent="openPreview({{ json_encode($doc['public_url']) }}, {{ json_encode($doc['uid']) }})"
                                    title="{{ $doc['uid'] }}"
                                >
                                    {{ $doc['uid'] }}
                                </a>
                            @else
                                <a href="#" wire:click.prevent="downloadPath({{ $doc['id'] }})" title="{{ $doc['uid'] }}">
                                    {{ $doc['uid'] }}
                                </a>
                            @endif
                        </span>
                    </div>
                    <div class="doc__meta">{{ $doc['sent_at']->translatedFormat('j M Y') }}</div>
                    <a href="#" wire:click.prevent="downloadPath({{ $doc['id'] }})" title="Downloaden">
                        <span class="icon-dl" aria-hidden="true">
                            @svgImg('img/icons/download.svg')
                        </span>
                    </a>
                    <a href="#" wire:click.prevent="removeAtIndex({{ $doc['id'] }})" title="Verwijderen">
                        <span class="icon-dl" aria-hidden="true">
                            @svgImg('img/icons/trash.svg')
                        </span>
                    </a>
                </div>
            @endforeach
        </div>
    </div>

    <div
        class="docs-list-upload docs-list-upload-note-pending mt-4"
        wire:loading.class="opacity-60 pointer-events-none"
        wire:target="documentFiles"
        :class="{ 'fi-border-primary fi-bg-primary/5': isDragging }"
        x-on:dragover="handleDragover"
        x-on:dragleave="handleDragleave"
        x-on:drop="handleDrop"
    >
        <label class="flex h-full w-full cursor-pointer items-center justify-center gap-1 px-3">
            <input
                type="file"
                wire:model="documentFiles"
                multiple
                class="sr-only"
                accept="{{ $acceptAttribute }}"
            />
            <span class="text-sm text-gray-600 dark:text-gray-400">
                Drag &amp; Drop je bestanden of
            </span>
            <span class="text-sm underline" style="color: var(--primary-600);">
                Bladeren
            </span>
        </label>
    </div>

    <template x-teleport="body">
        <div
            x-show="previewOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fi-modal-window fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            x-on:keydown.escape.window="closePreview()"
            x-on:click="closePreview()"
        >
            <div
                class="documents-preview-modal relative flex max-h-[90vh] w-full max-w-4xl flex-col rounded-xl bg-white shadow-xl dark:bg-gray-800"
                x-on:click.stop
            >
                <div class="documents-preview-modal__header flex shrink-0 items-center justify-end px-2 py-2">
                    <button
                        type="button"
                        x-on:click="closePreview()"
                        class="documents-preview-modal__close rounded-lg p-2 text-gray-500 outline-none transition hover:text-gray-700 focus:ring-2 focus:ring-gray-400 focus:ring-offset-2 dark:text-gray-400 dark:hover:text-gray-200"
                        aria-label="Sluiten"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                        </svg>
                    </button>
                </div>
                <div class="flex min-h-[200px] flex-1 items-center justify-center overflow-auto p-4">
                    <img
                        x-show="previewUrl"
                        :src="previewUrl"
                        alt="Preview"
                        class="max-h-[70vh] max-w-full object-contain"
                    />
                </div>
                <div class="documents-preview-modal__footer shrink-0 border-t border-gray-200 px-4 py-3 text-center dark:border-gray-700">
                    <span class="text-sm text-gray-600 dark:text-gray-400" x-text="previewName"></span>
                </div>
            </div>
        </div>
    </template>
</section>

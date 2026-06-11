@php
    $previewUrlBase = rtrim(preg_replace('#/0$#', '', $previewUrlTemplate ?? ''), '/');
@endphp
<section
    @if($sectionId) id="{{ $sectionId }}" @endif
    class="card documents-block"
    x-data="{
        isDragging: false,
        dragDepth: 0,
        previewOpen: false,
        openableIds: $wire.entangle('openableIds'),
        openableDocuments: $wire.entangle('openableDocuments'),
        currentPreviewIndex: 0,
        _docsListResizeObserver: null,
        _docsListWindowResizeBound: null,
        get currentPreviewId() {
            const ids = this.openableIds || [];
            if (!ids.length) return null;
            const len = ids.length;
            const i = ((this.currentPreviewIndex % len) + len) % len;
            return ids[i];
        },
        get previewUrl() {
            const id = this.currentPreviewId;
            return id ? '{{ $previewUrlBase }}/' + id : '';
        },
        get currentDocument() {
            const docs = this.openableDocuments || [];
            const len = docs.length;
            if (!len) return null;
            const i = ((this.currentPreviewIndex % len) + len) % len;
            return docs[i] || null;
        },
        get isPdfPreview() {
            return this.currentDocument && this.currentDocument.mime_type === 'application/pdf';
        },
        get isMailPreview() {
            if (!this.currentDocument) return false;
            const mime = (this.currentDocument.mime_type || '').toLowerCase();
            const extension = (this.currentDocument.extension || '').toLowerCase();
            return mime === 'application/vnd.ms-outlook' || extension === 'msg';
        },
        get positionLabel() {
            const total = (this.openableIds || []).length;
            if (!total) return '';
            const i = ((this.currentPreviewIndex % total) + total) % total;
            return (i + 1) + ' / ' + total;
        },
        updateDocsListInnerMaxHeight() {
            const section = this.$el;
            const inner = section.querySelector('.docs-list-inner');
            const upload = section.querySelector('.docs-list-upload');
            if (!inner) {
                return;
            }
            const innerRect = inner.getBoundingClientRect();
            let maxPx;
            if (upload) {
                const uploadRect = upload.getBoundingClientRect();
                maxPx = uploadRect.top - innerRect.top - 8;
            } else {
                const sectionRect = section.getBoundingClientRect();
                maxPx = sectionRect.bottom - innerRect.top - 8;
            }
            if (!Number.isFinite(maxPx)) {
                return;
            }
            maxPx = Math.max(96, Math.floor(maxPx));
            inner.style.maxHeight = maxPx + 'px';
        },
        bindDocsListHeightObservers() {
            const run = () => this.updateDocsListInnerMaxHeight();
            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    run();
                    requestAnimationFrame(run);
                });
            });
            if (this._docsListResizeObserver) {
                this._docsListResizeObserver.disconnect();
            }
            if (typeof ResizeObserver !== 'undefined') {
                this._docsListResizeObserver = new ResizeObserver(run);
                this._docsListResizeObserver.observe(this.$el);
            }
            if (!this._docsListWindowResizeBound) {
                this._docsListWindowResizeBound = run;
                window.addEventListener('resize', this._docsListWindowResizeBound);
            }
        },
        openPreview(mediaId) {
            const ids = this.openableIds || [];
            const idx = ids.indexOf(mediaId);
            if (idx >= 0) {
                this.currentPreviewIndex = idx;
                this.previewOpen = true;
            }
        },
        closePreview() {
            this.previewOpen = false;
        },
        goPrev() {
            const ids = this.openableIds || [];
            if (!ids.length) return;
            this.currentPreviewIndex = (this.currentPreviewIndex - 1 + ids.length) % ids.length;
        },
        goNext() {
            const ids = this.openableIds || [];
            if (!ids.length) return;
            this.currentPreviewIndex = (this.currentPreviewIndex + 1) % ids.length;
        },
        handleDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            this.isDragging = false;
            this.dragDepth = 0;
            const input = this.$el.querySelector('input[type=file]');
            if (!input || !e.dataTransfer.files.length) return;
            const dt = new DataTransfer();
            for (const file of e.dataTransfer.files) dt.items.add(file);
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        handleDragenter(e) {
            e.preventDefault();
            e.stopPropagation();
            this.dragDepth += 1;
            this.isDragging = true;
        },
        handleDragover(e) {
            e.preventDefault();
            e.stopPropagation();
            this.isDragging = true;
        },
        handleDragleave(e) {
            e.preventDefault();
            e.stopPropagation();
            this.dragDepth = Math.max(0, this.dragDepth - 1);
            this.isDragging = this.dragDepth > 0;
        },
    }"
    x-init="bindDocsListHeightObservers()"
    x-on:dragenter="handleDragenter"
    x-on:dragover="handleDragover"
    x-on:dragleave="handleDragleave"
    x-on:drop="handleDrop"
    :class="{ 'fi-border-primary': isDragging }"
    x-on:uploaded-docs-changed.window="
        setTimeout(() => {
            $wire.$refresh();
            setTimeout(() => updateDocsListInnerMaxHeight(), 200);
        }, 400);
    "
    wire:key="documents-block-{{ $uploadZoneKey }}-{{ $ownerId }}"
>
    @if(filled($blockTitle))
        <h3 class="card__title">{{ $blockTitle }}</h3>
        @if(!empty($info))
            <div style="margin-bottom: 5px; margin-top: -7px; font-size: 12px">
                {!! $info !!}
            </div>
        @endif
    @endif

    <div class="docs-list">
        <div class="docs-list-inner">
            @foreach ($documents as $doc)
                <div class="doc" wire:key="doc-{{ $uploadZoneKey }}-{{ $doc['media_id'] }}">
                    <div class="doc__left">
                        <span class="icon-file" aria-hidden="true">
                            @svgImg('img/icons/document.svg')
                        </span>
                        <span class="doc__name">
                            @if($doc['openable'] ?? false)
                                <a href="#" x-on:click.prevent="openPreview({{ $doc['media_id'] }})"
                                   title="{{ $doc['uid'] }}">
                                    {{ $doc['uid'] }}
                                </a>
                            @else
                                <a href="#" wire:click.prevent="downloadDocument({{ $doc['media_id'] }})"
                                   title="{{ $doc['uid'] }}">
                                    {{ $doc['uid'] }}
                                </a>
                            @endif
                        </span>
                    </div>
                    <div class="doc__meta">{{ $doc['sent_at']->translatedFormat('j M Y') }}</div>
                    <a href="#" wire:click.prevent="downloadDocument({{ $doc['media_id'] }})" title="Downloaden">
                        <span class="icon-dl" aria-hidden="true">
                            @svgImg('img/icons/download.svg')
                        </span>
                    </a>
                    @if ($readOnly || ! empty($doc['is_readonly']))
                        <span class="icon-dl opacity-40 cursor-not-allowed"
                              title="Dit document is alleen-lezen en kan niet verwijderd worden" aria-disabled="true">
                            @svgImg('img/icons/trash.svg')
                        </span>
                    @else
                        <a href="#" wire:click.prevent="deleteDocument({{ $doc['media_id'] }})" title="Verwijderen">
                            <span class="icon-dl" aria-hidden="true">
                                @svgImg('img/icons/trash.svg')
                            </span>
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @if (! $readOnly)
    <div
        class="docs-list-upload docs-list-upload-{{ $uploadZoneKey }} mt-4"
        wire:loading.class="opacity-60 pointer-events-none"
        wire:target="documentFiles"
        :class="{ 'fi-border-primary': isDragging }"
    >
        <label class="flex items-center justify-center gap-1 cursor-pointer w-full h-full px-3">
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
    @endif

    {{-- Preview modal (openable files only) --}}
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
            class="fi-modal-window fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
            role="dialog"
            aria-modal="true"
            x-on:keydown.escape.window="closePreview()"
            x-on:click="closePreview()"
        >
            <div
                class="documents-preview-modal relative flex flex-col max-h-[90vh] w-full max-w-4xl rounded-xl bg-white shadow-xl dark:bg-gray-800"
                x-on:click.stop
            >
                {{-- Header: filename as title + close --}}
                <div class="documents-preview-modal__header flex items-center justify-between gap-3 shrink-0 px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2
                        class="documents-preview-modal__title flex-1 min-w-0 text-base font-semibold text-gray-900 dark:text-gray-100 truncate"
                        x-text="currentDocument ? currentDocument.uid : ''"
                        :title="currentDocument ? currentDocument.uid : ''"
                    ></h2>
                    <button
                        type="button"
                        x-on:click="closePreview()"
                        class="documents-preview-modal__close shrink-0 p-2 rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400"
                        aria-label="Sluiten"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                             aria-hidden="true">
                            <path
                                d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
                        </svg>
                    </button>
                </div>

                {{-- Preview content --}}
                <div class="flex-1 overflow-auto p-4 flex items-center justify-center min-h-[200px]">
                    <template x-if="!isPdfPreview && !isMailPreview">
                        <img
                            x-show="currentPreviewId"
                            :src="previewUrl"
                            :key="currentPreviewId"
                            alt="Preview"
                            class="max-w-full max-h-[70vh] object-contain"
                        />
                    </template>

                    <template x-if="isPdfPreview">
                        <iframe
                            x-show="currentPreviewId"
                            :src="previewUrl"
                            :key="currentPreviewId"
                            title="PDF preview"
                            class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white"
                            style="height: min(82vh, calc(100vh - 240px)); width: min(100%, calc(min(82vh, calc(100vh - 180px)) * 210 / 297));"
                        ></iframe>
                    </template>

                    <template x-if="isMailPreview">
                        <iframe
                            x-show="currentPreviewId"
                            :src="previewUrl"
                            :key="currentPreviewId"
                            title="Mail preview"
                            class="rounded-lg border border-gray-200 dark:border-gray-700 bg-white"
                            style="height: min(82vh, calc(100vh - 240px)); width: 100%;"
                        ></iframe>
                    </template>
                </div>

                {{-- Footer: Vorige left, Volgende right, center: position --}}
                <div
                    class="documents-preview-modal__footer shrink-0 w-full px-4 py-3 flex items-center justify-between gap-4 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex shrink-0">
                        <button
                            type="button"
                            x-on:click="goPrev()"
                            class="fi-btn px-3 py-2 text-sm font-semibold rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        >
                            Vorige
                        </button>
                    </div>
                    <div
                        class="documents-preview-modal__center flex-1 min-w-0 flex items-center justify-center text-center">
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300"
                              x-text="positionLabel"></span>
                    </div>
                    <div class="flex shrink-0">
                        <button
                            type="button"
                            x-on:click="goNext()"
                            class="fi-btn px-3 py-2 text-sm font-semibold rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        >
                            Volgende
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</section>

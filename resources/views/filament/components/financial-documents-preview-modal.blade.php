{{-- Carousel preview for financiële documenten (zelfde opzet als documents-block). --}}
<template x-teleport="body">
    <div
        x-data="{
            previewItems: [],
            previewIndex: 0,
            orderId: null,
            mediaId: null,
            exactDocumentId: null,
            quotePreview: false,
            invoicePreview: false,
            orderHtmlPreview: false,
            previewOpen: false,
            get currentItem() {
                const items = this.previewItems || [];
                if (!items.length) {
                    return null;
                }
                const len = items.length;
                const i = ((this.previewIndex % len) + len) % len;
                return items[i] || null;
            },
            get currentTitle() {
                return this.currentItem?.title ?? '';
            },
            get positionLabel() {
                const total = (this.previewItems || []).length;
                if (!total) {
                    return '';
                }
                const i = ((this.previewIndex % total) + total) % total;
                return (i + 1) + ' / ' + total;
            },
            get previewSrc() {
                if (this.exactDocumentId) {
                    return '/exact-documents/' + this.exactDocumentId + '/preview';
                }
                if (this.mediaId) {
                    return '/media-preview/' + this.mediaId;
                }
                if (this.orderId) {
                    if (this.quotePreview) {
                        return '{{ url('/documents/quotes') }}/' + this.orderId + '/admin-preview';
                    }
                    return '/documents/' + this.orderId;
                }
                return '';
            },
            get isFullBleedPreview() {
                return this.quotePreview || this.invoicePreview || this.orderHtmlPreview;
            },
            applyPreviewItem(item) {
                if (!item || typeof item !== 'object') {
                    return;
                }
                this.orderId = item.orderId ?? null;
                this.mediaId = item.mediaId ?? null;
                this.exactDocumentId = item.exactDocumentId ?? null;
                this.quotePreview = !!item.quotePreview;
                this.invoicePreview = !!item.invoicePreview;
                this.orderHtmlPreview = !!item.orderHtmlPreview;
            },
            openFromDetail(detail) {
                const items = Array.isArray(detail.previewItems) ? detail.previewItems : [];
                if (!items.length) {
                    return;
                }
                this.previewItems = items;
                let idx = 0;
                if (detail.previewKey) {
                    const found = items.findIndex((item) => item.key === detail.previewKey);
                    if (found >= 0) {
                        idx = found;
                    }
                }
                this.previewIndex = idx;
                this.applyPreviewItem(items[idx]);
                this.previewOpen = true;
            },
            closePreview() {
                this.previewOpen = false;
                this.previewItems = [];
                this.previewIndex = 0;
                this.orderId = null;
                this.mediaId = null;
                this.exactDocumentId = null;
                this.quotePreview = false;
                this.invoicePreview = false;
                this.orderHtmlPreview = false;
            },
            goPrev() {
                const items = this.previewItems || [];
                if (items.length < 2) {
                    return;
                }
                this.previewIndex = (this.previewIndex - 1 + items.length) % items.length;
                this.applyPreviewItem(items[this.previewIndex]);
            },
            goNext() {
                const items = this.previewItems || [];
                if (items.length < 2) {
                    return;
                }
                this.previewIndex = (this.previewIndex + 1) % items.length;
                this.applyPreviewItem(items[this.previewIndex]);
            },
        }"
        x-on:open-modal.window="if ($event.detail.id === 'financial-documents-preview') { openFromDetail($event.detail) }"
    >
        <div
            x-show="previewOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fi-modal-window fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/50"
            role="dialog"
            aria-modal="true"
            x-on:keydown.escape.window="previewOpen && closePreview()"
            x-on:click="closePreview()"
        >
            <div
                class="documents-preview-modal financial-documents-preview-modal relative flex flex-col max-h-[90vh] w-full max-w-4xl rounded-xl bg-white shadow-xl dark:bg-gray-800"
                x-on:click.stop
            >
                <div class="documents-preview-modal__header flex items-center justify-between gap-3 shrink-0 px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h2
                        class="documents-preview-modal__title flex-1 min-w-0 text-base font-semibold text-gray-900 dark:text-gray-100 truncate"
                        x-text="currentTitle"
                        x-bind:title="currentTitle"
                    ></h2>
                    <button
                        type="button"
                        x-on:click="closePreview()"
                        class="documents-preview-modal__close shrink-0 p-2 rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400"
                        aria-label="Sluiten"
                    >
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
                        </svg>
                    </button>
                </div>

                <div
                    class="flex-1 overflow-auto p-4 flex items-center justify-center min-h-[200px]"
                    x-bind:class="{ 'financial-documents-preview-modal__body--full': isFullBleedPreview }"
                >
                    <template x-if="previewOpen && (orderId || mediaId || exactDocumentId)">
                        <iframe
                            x-bind:src="previewSrc"
                            x-bind:key="(exactDocumentId || mediaId || orderId) + '-' + previewIndex"
                            title="Document preview"
                            class="financial-documents-preview-modal__iframe rounded-lg border border-gray-200 dark:border-gray-700 bg-white"
                            x-bind:class="{ 'financial-documents-preview-modal__iframe--full': isFullBleedPreview }"
                        ></iframe>
                    </template>
                </div>

                <div class="documents-preview-modal__footer shrink-0 w-full px-4 py-3 flex items-center justify-between gap-4 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex shrink-0">
                        <button
                            type="button"
                            x-on:click="goPrev()"
                            x-bind:disabled="(previewItems || []).length < 2"
                            class="fi-btn px-3 py-2 text-sm font-semibold rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            Vorige
                        </button>
                    </div>
                    <div class="documents-preview-modal__center flex-1 min-w-0 flex items-center justify-center text-center">
                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300" x-text="positionLabel"></span>
                    </div>
                    <div class="flex shrink-0">
                        <button
                            type="button"
                            x-on:click="goNext()"
                            x-bind:disabled="(previewItems || []).length < 2"
                            class="fi-btn px-3 py-2 text-sm font-semibold rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            Volgende
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

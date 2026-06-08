@php
    /** @var \App\Models\Note|null $note */
    /** @var \App\Models\Order\Main|null $order */
@endphp

@php
    $previewUrlBase = rtrim(preg_replace('#/0$#', '', route('documents.media-preview', ['id' => 0])), '/');
@endphp

<div
    class="note-view-panel space-y-4"
    x-data="{
        activeTab: 'comments',
        previewOpen: false,
        openableIds: @js(
            $attachments
                ->filter(fn ($media) => in_array($media->mime_type, config('documents.openable_mime_types', []), true)
                    || in_array(mb_strtolower((string) ($media->extension ?? '')), config('documents.openable_extensions', []), true))
                ->pluck('id')
                ->values()
                ->all()
        ),
        openableDocuments: @js(
            $attachments
                ->filter(fn ($media) => in_array($media->mime_type, config('documents.openable_mime_types', []), true)
                    || in_array(mb_strtolower((string) ($media->extension ?? '')), config('documents.openable_extensions', []), true))
                ->map(fn ($media) => [
                    'media_id' => $media->id,
                    'uid' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'extension' => mb_strtolower((string) ($media->extension ?? '')),
                ])
                ->values()
                ->all()
        ),
        currentPreviewIndex: 0,
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
    }"
>
    @if($note)
        @php
            $commentsCount = $comments->count();
            $filesCount = $attachments->count();
        @endphp
        <ul class="kv">
            <li>
                <span class="k">{{ $note->customer?->type === \App\Enums\CustomerType::Dealer ? 'Dealer:' : 'Klant:' }}</span>
                <span class="v">{{ $note->author ?? '-' }}</span>
            </li>
            <li>
                <span class="k">Type:</span>
                <span class="v">{{ $note->type?->getLabel() ?? '-' }}</span>
            </li>
            @if($isOrderRelated)
                <li>
                    <span class="k">Aanvraag:</span>
                    <span class="v">
                        @if($order)
                            <a
                                href="{{ route('filament.app.resources.mains.view', ['record' => $order->id]) }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="main-request-number-link hover:underline"
                            >
                                {{ $order->getDescriptor() }}
                            </a>
                        @else
                            -
                        @endif
                    </span>
                </li>
            @endif
            <li>
                <span class="k">Door:</span>
                <span class="v">{{ $note->user?->getName() ?? '-' }}</span>
            </li>
            <li>
                <span class="k">Getagde collega's:</span>
                <span class="v note-view-panel__tagged-colleagues">{{ $taggedColleaguesLabel }}</span>
            </li>
            <li>
                <span class="k">Status:</span>
                <span class="v">
                    <select
                        wire:model.live="status"
                        @class([
                            'fi-input note-view-panel__status-select block w-full max-w-[130px] shadow-sm text-sm',
                            'is-open' => $status === 'open',
                            'is-ongoing' => $status === 'ongoing',
                            'is-completed' => $status === 'completed',
                        ])
                    >
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </span>
            </li>
        </ul>

        <hr class="note-view-panel__divider">

        <div class="note-view-panel__content">
            {!! $note->content !!}
        </div>

        <section class="note-view-panel__tabs-card">
            <div class="tabs" role="tablist" aria-label="Notitie tabs">
                <button
                    class="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'comments'"
                    x-on:click="activeTab = 'comments'"
                >
                    Reacties ({{ $commentsCount }})
                </button>
                <button
                    class="tab"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === 'files'"
                    x-on:click="activeTab = 'files'"
                >
                    Bestanden ({{ $filesCount }})
                </button>
            </div>

            <div x-show="activeTab === 'comments'" x-cloak>
                <div class="note-view-panel__comment-compose space-y-2">
                    <textarea
                        id="note-comment"
                        wire:model.live="comment"
                        required
                        placeholder="Plaats je reactie"
                        aria-label="Reactie"
                        class="fi-input note-view-panel__comment-textarea block w-full shadow-sm text-sm"
                    ></textarea>
                    @error('comment')
                        <p class="text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                    <div>
                        <button
                            type="button"
                            wire:click="saveComment"
                            @disabled(trim($comment) === '')
                            class="fi-btn fi-btn-size-sm fi-btn-color-primary note-view-panel__comment-submit"
                        >
                            Reactie plaatsen
                        </button>
                    </div>
                </div>

                <div class="note-view-panel__comments space-y-3">
                    @forelse($comments as $item)
                        @php
                            $authorUser = $item->user;
                            $authorName = $authorUser?->getName() ?? '-';
                            if ($authorUser !== null) {
                                $avatarUrl = \Filament\Facades\Filament::getUserAvatarUrl($authorUser);
                            } else {
                                $avatarName = urlencode($authorName);
                                $avatarBg = urlencode('oklch(0.141 0.005 285.823)');
                                $avatarUrl = 'https://ui-avatars.com/api/?name=' . $avatarName . '&color=FFFFFF&background=' . $avatarBg;
                            }
                        @endphp
                        <article class="note-view-panel__comment">
                            <img class="note-view-panel__avatar" src="{{ $avatarUrl }}" alt="{{ $authorName }}">
                            <div class="note-view-panel__comment-meta">
                                <strong>{{ $authorName }}</strong>
                                <span>{{ $item->created_at?->format('d-m-Y H:i') }}</span>
                            </div>
                            <p>{{ $item->comment }}</p>
                        </article>
                    @empty @endforelse
                </div>
            </div>

            <div x-show="activeTab === 'files'" x-cloak>
                <div class="docs-list">
                    <div class="docs-list-inner">
                        @forelse ($attachments as $media)
                            @php
                                $isOpenable = in_array($media->mime_type, config('documents.openable_mime_types', []), true)
                                    || in_array(mb_strtolower((string) ($media->extension ?? '')), config('documents.openable_extensions', []), true);
                            @endphp
                            <div class="doc">
                                <div class="doc__left">
                                    <span class="icon-file" aria-hidden="true">
                                        @svgImg('img/icons/document.svg')
                                    </span>
                                    <span class="doc__name">
                                        @if($isOpenable)
                                            <a href="#" x-on:click.prevent="openPreview({{ $media->id }})" title="{{ $media->file_name }}">
                                                {{ $media->file_name }}
                                            </a>
                                        @else
                                            <a
                                                href="{{ route('documents.media-preview', ['id' => $media->id]) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="{{ $media->file_name }}"
                                            >
                                                {{ $media->file_name }}
                                            </a>
                                        @endif
                                    </span>
                                </div>
                                <div class="doc__meta">{{ $media->created_at?->translatedFormat('j M Y') }}</div>
                                <a href="{{ route('documents.media-preview', ['id' => $media->id]) }}" target="_blank" rel="noopener noreferrer" title="Openen">
                                    <span class="icon-dl" aria-hidden="true">
                                        @svgImg('img/icons/download.svg')
                                    </span>
                                </a>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Nog geen bestanden.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </section>

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
                    <div class="documents-preview-modal__header flex items-center justify-end shrink-0 px-2 py-2">
                        <button
                            type="button"
                            x-on:click="closePreview()"
                            class="documents-preview-modal__close p-2 rounded-lg text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400"
                            aria-label="Sluiten"
                        >
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                            </svg>
                        </button>
                    </div>

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
                                style="height: min(82vh, calc(100vh - 180px)); width: min(100%, calc(min(82vh, calc(100vh - 180px)) * 210 / 297));"
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

                    <div class="documents-preview-modal__footer shrink-0 w-full px-4 py-3 flex items-center justify-between gap-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex shrink-0">
                            <button
                                type="button"
                                x-on:click="goPrev()"
                                class="fi-btn px-3 py-2 text-sm font-semibold rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                            >
                                Vorige
                            </button>
                        </div>
                        <div class="documents-preview-modal__center flex-1 min-w-0 flex flex-col items-center justify-center text-center">
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300" x-text="positionLabel"></span>
                            <div class="flex items-center justify-center gap-2 mt-1 min-w-0">
                                <span class="text-sm text-gray-600 dark:text-gray-400 truncate max-w-[200px]" x-text="currentDocument ? currentDocument.uid : ''"></span>
                            </div>
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
    @endif
</div>

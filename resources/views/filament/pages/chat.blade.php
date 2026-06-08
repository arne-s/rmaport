<x-filament-panels::page full-height>
    <div
        class="fi-chat-page"
        @if (config('filament.broadcasting.echo'))
            @chat-message-received.window="$wire.refreshChatData()"
        @endif
        wire:poll.3s.visible="refreshChatData"
        x-data="{
            mobileView: 'sidebar',
            init() {
                document.body.classList.add('page-chat')
            },
            destroy() {
                document.body.classList.remove('page-chat')
            },
            onChatClickCapture($event) {
                const a = $event.target.closest('a.fi-chat-open-note')
                if (! a) {
                    return
                }
                $event.preventDefault()
                const id = parseInt(a.getAttribute('data-note-id') || '', 10)
                if (! Number.isFinite(id)) {
                    return
                }
                $wire.openLinkedNote(id)
            },
        }"
        x-init="init()"
        @click.capture="onChatClickCapture($event)"
    >
        <div class="fi-chat-layout" :class="{ 'fi-chat-layout--mob-chat': mobileView === 'chat' }">
            <aside class="fi-chat-sidebar" wire:key="chat-sidebar">
                <label class="fi-chat-sidebar__search-label">
                    <span class="sr-only">{{ __('Zoeken') }}</span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="userSearch"
                        class="fi-chat-sidebar__search"
                        placeholder="{{ __('Zoeken') }}"
                        autocomplete="off"
                    />
                </label>
                <ul class="fi-chat-user-list" role="list">
                    @forelse ($sidebarUserRows as $row)
                        <li wire:key="chat-sidebar-user-{{ $row['id'] }}">
                            <button
                                type="button"
                                wire:click="selectUser({{ $row['id'] }})"
                                @click="mobileView = 'chat'"
                                @class([
                                    'fi-chat-user-row',
                                    'fi-chat-user-row--active' => $selectedUserId === $row['id'],
                                ])
                                @if (($row['unread_count'] ?? 0) > 0)
                                    aria-label="{{ $row['name'] }}, {{ $row['unread_count'] }} {{ $row['unread_count'] === 1 ? __('ongelezen bericht') : __('ongelezen berichten') }}"
                                @endif
                            >
                                <span
                                    @class([
                                        'fi-chat-user-row__avatar',
                                        'fi-chat-user-row__avatar--online' => ($row['is_online'] ?? false),
                                    ])
                                >
                                    <img src="{{ $row['avatar_url'] }}" alt="" width="40" height="40" loading="lazy" />
                                </span>
                                <span class="fi-chat-user-row__name">{{ $row['name'] }}</span>
                                <span class="fi-chat-user-row__unread" aria-hidden="true">
                                    @if (($row['unread_count'] ?? 0) > 0)
                                        <span class="fi-chat-user-row__unread-badge">{{ $row['unread_count'] }}</span>
                                    @endif
                                </span>
                            </button>
                        </li>
                    @empty
                        <li class="fi-chat-user-list__empty">{{ __('Geen gebruikers gevonden.') }}</li>
                    @endforelse
                </ul>
            </aside>

            <section class="fi-chat-main" aria-label="{{ __('Chatberichten') }}">
                @if ($selectedUserId === null)
                    <div class="fi-chat-main__empty">
                        {{ __('Selecteer een gebruiker om te chatten.') }}
                    </div>
                @else
                    <header class="fi-chat-main__header">
                        <button
                            type="button"
                            class="fi-chat-back-btn"
                            @click="mobileView = 'sidebar'"
                            aria-label="{{ __('Terug naar Gesprekken') }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="black" width="25" height="25" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>
                        </button>
                        <span
                            @class([
                                'fi-chat-main__header-avatar',
                                'fi-chat-main__header-avatar--online' => $selectedUserIsOnline,
                            ])
                        >
                            <img src="{{ $selectedUserAvatarUrl }}" alt="" width="40" height="40" />
                        </span>
                        <span class="fi-chat-main__header-name">{{ $selectedUserName }}</span>
                    </header>

                    <div
                        class="fi-chat-messages"
                        wire:key="messages-{{ $selectedUserId }}"
                        x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                        @if ($this->hasActiveUnreadHighlights())
                            wire:poll.1s="pruneExpiredUnreadHighlights"
                        @endif
                    >
                        <div
                            class="fi-chat-messages-loading"
                            wire:loading.flex
                            wire:target="selectUser"
                        ></div>
                        <div class="fi-chat-messages-stack" wire:loading.class="fi-chat-messages-stack--hidden" wire:target="selectUser">
                            @foreach ($messageRows as $msg)
                            @if ($msg['show_day_divider'])
                                <div class="fi-chat-divider">
                                    <span>{{ $msg['divider_label'] }}</span>
                                </div>
                            @endif
                            @php
                                $isOwn = $msg['from_user_id'] === auth()->id();
                            @endphp
                            <div wire:key="chat-msg-{{ $msg['id'] }}" @class(['fi-chat-row', 'fi-chat-row--own' => $isOwn, 'fi-chat-row--other' => ! $isOwn])>
                                @if (! $isOwn)
                                    <span
                                        @class([
                                            'fi-chat-row__avatar',
                                            'fi-chat-row__avatar--online' => $selectedUserIsOnline,
                                        ])
                                    >
                                        <img src="{{ $selectedUserAvatarUrl }}" alt="" width="45" height="45" />
                                    </span>
                                @endif
                                <div
                                    @class([
                                        'fi-chat-bubble',
                                        'fi-chat-bubble--unread' => ($msg['show_unread_highlight'] ?? false),
                                    ])
                                >
                                    {!! $msg['content_html'] !!}
                                </div>
                                @if ($isOwn)
                                    <span class="fi-chat-row__avatar">
                                        <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl(auth()->user()) }}" alt="" width="45" height="45" />
                                    </span>
                                @endif
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <form
                        class="fi-chat-composer"
                        wire:key="chat-composer-{{ $selectedUserId }}"
                        @submit.prevent="submitComposer()"
                        x-data="{
                                mentionOpen: false,
                                items: [],
                                activeIndex: 0,
                                mentionAtIndex: null,
                                debounceTimer: null,
                                mentionSearched: false,
                                /** After Escape: geen popup/fetch tot gebruiker uit @-context typet (anders weer normaal typen). */
                                mentionSuppressed: false,
                                init() {
                                    this.$nextTick(() => {
                                        requestAnimationFrame(() => {
                                            this.$refs.composerInput?.focus({ preventScroll: true })
                                        })
                                    })
                                },
                                parseMention(val, cursor) {
                                    const before = val.slice(0, cursor)
                                    const m = before.match(/(?:^|\s)@([A-Za-z0-9-]*)$/)
                                    if (! m) return null
                                    return { query: m[1], atIndex: before.length - m[1].length - 1 }
                                },
                                closeMention() {
                                    this.mentionOpen = false
                                    this.items = []
                                    this.activeIndex = 0
                                    this.mentionAtIndex = null
                                },
                                scrollActiveMentionIntoView() {
                                    this.$nextTick(() => {
                                        const panel = this.$refs.mentionPanel
                                        if (! panel) {
                                            return
                                        }
                                        const active = panel.querySelector('.fi-chat-mention-option--active')
                                        active?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
                                    })
                                },
                                syncMentionFromInput(el) {
                                    if (! el) return
                                    const val = el.value
                                    const cursor = el.selectionStart ?? val.length
                                    const parsed = this.parseMention(val, cursor)
                                    if (! parsed) {
                                        this.mentionSuppressed = false
                                        this.closeMention()
                                        return
                                    }
                                    if (this.mentionSuppressed) {
                                        return
                                    }
                                    this.mentionOpen = true
                                    this.mentionAtIndex = parsed.atIndex
                                    this.mentionSearched = false
                                    clearTimeout(this.debounceTimer)
                                    const q = parsed.query
                                    this.debounceTimer = setTimeout(() => {
                                        $wire.searchChatMentionSuggestions(q).then((rows) => {
                                            const input = this.$refs.composerInput
                                            if (! input) {
                                                return
                                            }
                                            const valNow = input.value
                                            const cursorNow = input.selectionStart ?? valNow.length
                                            const parsedNow = this.parseMention(valNow, cursorNow)
                                            const stillRelevant = Boolean(parsedNow && parsedNow.query === q && ! this.mentionSuppressed)
                                            if (! stillRelevant) {
                                                return
                                            }
                                            this.items = Array.isArray(rows) ? rows : []
                                            this.activeIndex = 0
                                            this.mentionSearched = true
                                            this.scrollActiveMentionIntoView()
                                        }).catch(() => {
                                            const input = this.$refs.composerInput
                                            if (! input) {
                                                return
                                            }
                                            const valNow = input.value
                                            const cursorNow = input.selectionStart ?? valNow.length
                                            const parsedNow = this.parseMention(valNow, cursorNow)
                                            if (! parsedNow || parsedNow.query !== q || this.mentionSuppressed) {
                                                return
                                            }
                                            this.items = []
                                            this.mentionSearched = true
                                        })
                                    }, 150)
                                },
                                submitComposer() {
                                    const el = this.$refs.composerInput
                                    const v = el ? el.value : ''
                                    return $wire.set('newMessage', v).then(() => $wire.sendMessage())
                                },
                                onComposerInput($event) {
                                    this.syncMentionFromInput($event.target)
                                },
                                onComposerKeyup($event) {
                                    if (this.mentionSuppressed) return
                                    if ($event.isComposing) return
                                    const k = $event.key
                                    if (k === 'Enter' || k === 'Escape' || k === 'ArrowDown' || k === 'ArrowUp' || k === 'Tab') {
                                        return
                                    }
                                    if (k.length === 1 || k === 'Backspace' || k === 'Delete') {
                                        this.$nextTick(() => this.syncMentionFromInput($event.target))
                                    }
                                },
                                pickMention(item) {
                                    const input = this.$refs.composerInput
                                    if (! input || this.mentionAtIndex === null) return
                                    const val = input.value
                                    const cursor = input.selectionStart ?? val.length
                                    const before = val.slice(0, this.mentionAtIndex)
                                    const after = val.slice(cursor)
                                    let insert = ''
                                    if (item.kind === 'main' && item.uid) {
                                        insert = '@' + item.uid + ' '
                                    } else if (item.kind === 'klant') {
                                        insert = '[[klant:' + item.id + ']] '
                                    } else if (item.kind === 'dealer') {
                                        insert = '[[dealer:' + item.id + ']] '
                                    } else if (item.kind === 'notitie') {
                                        insert = '[[notitie:' + item.id + ']] '
                                    } else {
                                        return
                                    }
                                    const newVal = before + insert + after
                                    $wire.set('newMessage', newVal)
                                    this.mentionSuppressed = false
                                    this.closeMention()
                                    this.$nextTick(() => {
                                        const pos = before.length + insert.length
                                        input.setSelectionRange(pos, pos)
                                        input.focus()
                                    })
                                },
                                onComposerKeydown($event) {
                                    if ($event.key === 'Enter') {
                                        if (this.mentionOpen && this.items.length > 0) {
                                            $event.preventDefault()
                                            $event.stopPropagation()
                                            this.pickMention(this.items[this.activeIndex])
                                            return
                                        }
                                        $event.preventDefault()
                                        this.submitComposer()
                                        return
                                    }
                                    if ($event.key === 'ArrowDown' && this.mentionOpen && this.items.length > 0) {
                                        $event.preventDefault()
                                        this.activeIndex = (this.activeIndex + 1) % this.items.length
                                        this.scrollActiveMentionIntoView()
                                        return
                                    }
                                    if ($event.key === 'ArrowUp' && this.mentionOpen && this.items.length > 0) {
                                        $event.preventDefault()
                                        this.activeIndex = (this.activeIndex - 1 + this.items.length) % this.items.length
                                        this.scrollActiveMentionIntoView()
                                        return
                                    }
                                    if ($event.key === 'Escape') {
                                        if (!this.mentionOpen && !this.mentionSuppressed) {
                                            return
                                        }
                                        $event.preventDefault()
                                        $event.stopPropagation()
                                        this.mentionSuppressed = true
                                        this.closeMention()
                                        return
                                    }
                                },
                            }"
                    >
                        <div class="fi-chat-composer__field fi-chat-composer__field--mention">
                            <label class="sr-only" for="fi-chat-new-message">{{ __('Bericht') }}</label>
                            <div class="fi-chat-mention-wrap">
                                <input
                                    id="fi-chat-new-message"
                                    x-ref="composerInput"
                                    type="text"
                                    wire:model="newMessage"
                                    @input="onComposerInput($event)"
                                    @keyup="onComposerKeyup($event)"
                                    @keydown="onComposerKeydown($event)"
                                    class="fi-chat-composer__input"
                                    placeholder="{{ __('Typ een bericht…') }}"
                                    autocomplete="off"
                                />
                                <div
                                    class="fi-chat-mention-panel"
                                    x-ref="mentionPanel"
                                    x-show="mentionOpen"
                                    x-cloak
                                    x-transition
                                >
                                    <template x-if="items.length > 0">
                                        <ul class="fi-chat-mention-list" role="listbox">
                                            <template x-for="(item, idx) in items" :key="item.kind + '-' + item.id">
                                                <li role="option">
                                                    <button
                                                        type="button"
                                                        class="fi-chat-mention-option"
                                                        :class="{ 'fi-chat-mention-option--active': idx === activeIndex }"
                                                        @mousedown.prevent="pickMention(item)"
                                                    >
                                                        <span class="fi-chat-mention-option__kind" x-text="item.kind_label + ':'"></span>
                                                        <span class="fi-chat-mention-option__label" x-text="item.label"></span>
                                                    </button>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>
                                    <p class="fi-chat-mention-empty" x-show="items.length === 0 && !mentionSearched">
                                        {{ __('Zoeken…') }}
                                    </p>
                                    <p class="fi-chat-mention-empty" x-show="items.length === 0 && mentionSearched">
                                        {{ __('Geen resultaten.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="fi-chat-composer__send" title="{{ __('Versturen') }}">
                            <x-filament::icon
                                :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperAirplane"
                                class="fi-chat-composer__send-icon"
                            />
                            <span class="sr-only">{{ __('Versturen') }}</span>
                        </button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>

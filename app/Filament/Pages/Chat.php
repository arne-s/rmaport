<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Events\ChatMessageSent;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\Mains\MainResource;
use App\Models\ChatMessage;
use App\Models\Customer;
use App\Models\Note;
use App\Models\Order\Main;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Chat extends Page
{
    /**
     * Show a timestamp divider between messages when the gap exceeds this many seconds.
     */
    private const int TIMESTAMP_DIVIDER_GAP_SECONDS = 6 * 3600;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Chat';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.pages.chat';

    public string $userSearch = '';

    public ?int $selectedUserId = null;

    public string $newMessage = '';

    public ?string $selectedUserName = null;

    public ?string $selectedUserAvatarUrl = null;

    public bool $selectedUserIsOnline = false;

    /**
     * @var array<int, array{id: int, name: string, avatar_url: string, last_online_at: ?string, is_online: bool, unread_count: int}>
     */
    public array $sidebarUserRows = [];

    /**
     * Message ids → unix time until which incoming-unread highlight (#f0fff0) is shown.
     *
     * @var array<int, int>
     */
    public array $unreadHighlightUntil = [];

    /**
     * @var array<int, array{id: int, from_user_id: int, content: string, content_html: string, created_at: string, show_day_divider: bool, divider_label: string, show_unread_highlight: bool}>
     */
    public array $messageRows = [];

    public function mount(): void
    {
        $this->loadSidebarUsers();
        $this->openLatestUnreadConversationIfAny();
        if ($this->selectedUserId === null) {
            $this->openLastConversationPartnerIfAny();
        }
    }

    protected function openLatestUnreadConversationIfAny(): void
    {
        $senderId = ChatMessage::latestUnreadSenderIdForRecipient(Auth::id());
        if ($senderId === null) {
            return;
        }

        $this->selectUser($senderId);
    }

    protected function openLastConversationPartnerIfAny(): void
    {
        $partnerId = ChatMessage::latestConversationPartnerUserId(Auth::id());
        if ($partnerId === null || $partnerId === Auth::id()) {
            return;
        }

        $this->selectUser($partnerId);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-chat'];
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /**
     * Non-empty zodat het header-blok het logo + broodkruimels naar #customBreadcrumbs teleporteert (custom topbar).
     */
    public function getBreadcrumbs(): array
    {
        return [
            url()->current() => __('Chat'),
        ];
    }

    public function refreshChatData(): void
    {
        $previousLastMessageId = $this->getLastDisplayedMessageId();

        $this->loadMessages();
        $this->loadSidebarUsers();

        $currentLastMessageId = $this->getLastDisplayedMessageId();

        if ($this->selectedUserId !== null
            && $currentLastMessageId !== null
            && $currentLastMessageId !== $previousLastMessageId) {
            $this->scrollChatMessagesToEnd();
        }
    }

    /**
     * Drop expired unread highlights so the UI reverts after ~5s without waiting for the chat poll interval.
     */
    public function pruneExpiredUnreadHighlights(): void
    {
        $now = time();
        $filtered = array_filter(
            $this->unreadHighlightUntil,
            static fn (int $until): bool => $until > $now
        );

        if ($filtered === $this->unreadHighlightUntil) {
            return;
        }

        $this->unreadHighlightUntil = $filtered;
        $this->loadMessages();
    }

    public function hasActiveUnreadHighlights(): bool
    {
        $now = time();

        foreach ($this->unreadHighlightUntil as $until) {
            if ($until > $now) {
                return true;
            }
        }

        return false;
    }

    public function updatedUserSearch(): void
    {
        $this->loadSidebarUsers();
    }

    public function selectUser(int $userId): void
    {
        if ($userId === Auth::id()) {
            return;
        }

        $exists = User::query()
            ->where('id', $userId)
            ->exists();

        if (! $exists) {
            return;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }

        if ($this->selectedUserId !== $userId) {
            $this->unreadHighlightUntil = [];
        }

        $this->selectedUserId = $userId;
        $this->selectedUserName = $user->name;
        $this->selectedUserAvatarUrl = Filament::getUserAvatarUrl($user);
        $this->selectedUserIsOnline = $user->isConsideredOnline();
        $this->loadMessages();
        $this->loadSidebarUsers();
        $this->scrollChatMessagesToEnd();
    }

    /**
     * @throws ValidationException
     */
    public function sendMessage(): void
    {
        if ($this->selectedUserId === null) {
            throw ValidationException::withMessages([
                'newMessage' => __('Selecteer eerst een gebruiker.'),
            ]);
        }

        $this->validate([
            'newMessage' => ['required', 'string', 'max:10000'],
        ]);

        $message = ChatMessage::query()->create([
            'from_user_id' => Auth::id(),
            'to_user_id' => $this->selectedUserId,
            'content' => $this->newMessage,
        ]);

        $this->broadcastChatMessageSent($message);

        $this->newMessage = '';
        $this->loadMessages();
        $this->loadSidebarUsers();
        $this->scrollChatMessagesToEnd();
    }

    protected function broadcastChatMessageSent(ChatMessage $message): void
    {
        $pendingBroadcast = broadcast(new ChatMessageSent($message));

        $socketId = request()->header('X-Socket-ID');

        if (is_string($socketId) && $socketId !== '' && $socketId !== 'undefined') {
            $pendingBroadcast->toOthers();
        }
    }

    /**
     * Open de globale notitie-modal (zelfde als elders in het panel) vanuit een link in een chatbericht.
     */
    public function openLinkedNote(int $noteId): void
    {
        $this->dispatch('open-edit-note', noteId: $noteId)
            ->component('global-edit-note');
    }

    /**
     * Suggestions na `@`: aanvragen, klanten, dealers, notities.
     *
     * @return list<array{kind: 'main'|'klant'|'dealer'|'notitie', id: int, label: string, kind_label: string, uid?: string}>
     */
    public function searchChatMentionSuggestions(string $query): array
    {
        $query = trim($query);
        if (strlen($query) > 64) {
            return [];
        }

        $perType = $query === '' ? 5 : 8;
        $rows = [];

        $base = Main::query()
            ->whereNotNull('uid')
            ->where('uid', '!=', '');

        if ($query === '') {
            $mains = (clone $base)
                ->orderByDesc('id')
                ->limit($perType)
                ->get();
        } else {
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $likePrefix = $needle.'%';
            $likeContains = '%'.$needle.'%';

            $mains = (clone $base)
                ->where(function (Builder $w) use ($likePrefix, $likeContains): void {
                    $w->where(function (Builder $uid) use ($likePrefix, $likeContains): void {
                        $uid->where('orders.uid', 'like', $likePrefix)
                            ->orWhere('orders.uid', 'like', $likeContains);
                    })
                        ->orWhereHas('customer', function (Builder $c) use ($likeContains): void {
                            $c->where(function (Builder $cc) use ($likeContains): void {
                                $cc->where('first_name', 'like', $likeContains)
                                    ->orWhere('last_name', 'like', $likeContains);
                            });
                        })
                        ->orWhereHas('billingCustomer', function (Builder $c) use ($likeContains): void {
                            $c->where('name', 'like', $likeContains);
                        })
                        ->orWhereHas('mainReport', function (Builder $r) use ($likeContains): void {
                            $r->where(function (Builder $mr) use ($likeContains): void {
                                $mr->where('dealer_name', 'like', $likeContains)
                                    ->orWhere('customer_name', 'like', $likeContains)
                                    ->orWhere('order_uid', 'like', $likeContains);
                            });
                        });
                })
                ->orderBy('orders.uid')
                ->limit($perType)
                ->get();
        }

        foreach ($mains as $main) {
            $rows[] = [
                'kind' => 'main',
                'id' => (int) $main->getKey(),
                'uid' => (string) $main->getUid(),
                'label' => $main->getDescriptor(),
                'kind_label' => __('Aanvraag'),
            ];
        }

        $customerQuery = self::chatMentionCustomersQuery()
            ->where('type', '!=', CustomerType::Dealer->value);
        if ($query !== '') {
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $likeContains = '%'.$needle.'%';
            $customerQuery->where(function (Builder $w) use ($likeContains): void {
                $w->where('first_name', 'like', $likeContains)
                    ->orWhere('last_name', 'like', $likeContains)
                    ->orWhere('name', 'like', $likeContains);
            });
        }
        $customers = $customerQuery
            ->orderBy('name')
            ->limit($perType)
            ->get();

        foreach ($customers as $customer) {
            $label = trim((string) ($customer->getName() ?? ''));
            if ($label === '') {
                continue;
            }

            $rows[] = [
                'kind' => 'klant',
                'id' => (int) $customer->getKey(),
                'label' => $label,
                'kind_label' => __('Klant'),
            ];
        }

        $dealerQuery = self::chatMentionCustomersQuery()
            ->where('type', CustomerType::Dealer->value);
        if ($query !== '') {
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $likeContains = '%'.$needle.'%';
            $dealerQuery->where('name', 'like', $likeContains);
        }
        $dealers = $dealerQuery
            ->orderBy('name')
            ->limit($perType)
            ->get();

        foreach ($dealers as $dealer) {
            $label = trim((string) ($dealer->getName() ?? ''));
            if ($label === '') {
                continue;
            }

            $rows[] = [
                'kind' => 'dealer',
                'id' => (int) $dealer->getKey(),
                'label' => $label,
                'kind_label' => __('Dealer'),
            ];
        }

        $noteQuery = Note::query();
        if ($query !== '') {
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $likeContains = '%'.$needle.'%';
            $noteQuery->where('content', 'like', $likeContains);
        }
        $notes = $noteQuery
            ->orderByDesc('id')
            ->limit($perType)
            ->get();

        foreach ($notes as $note) {
            $snippet = Str::limit(trim(strip_tags((string) ($note->content ?? ''))), 72);
            $rows[] = [
                'kind' => 'notitie',
                'id' => (int) $note->getKey(),
                'label' => $snippet !== '' ? $snippet : ('#'.$note->getKey()),
                'kind_label' => __('Notitie'),
            ];
        }

        return $rows;
    }

    /**
     * Escape plain text en maak klikbare verwijzingen: `[[notitie:id]]` / `[[klant:id]]` / `[[dealer:id]]`
     * (en legacy `[[note:id]]`, `[[customer:id]]`, `[[company:id]]`), plus `@uid` / `#uid` (aanvraag).
     */
    private static function escapeAndLinkifyChatReferences(string $content): string
    {
        if ($content === '') {
            return '';
        }

        $escaped = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        $noteFormat = function (int $id, Collection $records): string {
            /** @var Note|null $note */
            $note = $records->get($id);
            $snippet = $note ? Str::limit(trim(strip_tags((string) $note->content)), 48) : '';
            $text = $snippet !== ''
                ? __('Notitie').': '.htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8')
                : __('Notitie').' #'.htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8');

            return '<a href="#" class="fi-chat-inline-link fi-chat-open-note" data-note-id="'.htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8').'">'.$text.'</a>';
        };
        $escaped = self::linkifyBracketToken($escaped, 'notitie', $noteFormat);
        $escaped = self::linkifyBracketToken($escaped, 'note', $noteFormat);

        $escaped = self::linkifyBracketToken($escaped, 'klant', function (int $id, Collection $records): string {
            /** @var Customer|null $customer */
            $customer = $records->get($id);
            if ($customer === null) {
                return '[[klant:'.htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8').']]';
            }

            $url = CustomerResource::getUrl('edit', ['record' => $customer]);
            $text = htmlspecialchars(__('Klant').': '.$customer->getName(), ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

            return '<a href="'.$href.'" class="fi-chat-inline-link" target="_blank" rel="noopener noreferrer">'.$text.'</a>';
        });
        $escaped = self::linkifyBracketToken($escaped, 'customer', function (int $id, Collection $records): string {
            /** @var Customer|null $customer */
            $customer = $records->get($id);
            if ($customer === null) {
                return '[[customer:'.htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8').']]';
            }

            $url = CustomerResource::getUrl('edit', ['record' => $customer]);
            $text = htmlspecialchars(__('Klant').': '.$customer->getName(), ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

            return '<a href="'.$href.'" class="fi-chat-inline-link" target="_blank" rel="noopener noreferrer">'.$text.'</a>';
        });

        $dealerFormat = function (int $id, Collection $records): string {
            /** @var Customer|null $dealer */
            $dealer = $records->get($id);
            if ($dealer === null) {
                return '[[dealer:'.htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8').']]';
            }

            $url = CustomerResource::getUrl('edit', ['record' => $dealer]);
            $text = htmlspecialchars(__('Dealer').': '.$dealer->getName(), ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

            return '<a href="'.$href.'" class="fi-chat-inline-link" target="_blank" rel="noopener noreferrer">'.$text.'</a>';
        };
        $escaped = self::linkifyBracketToken($escaped, 'dealer', $dealerFormat);
        $escaped = self::linkifyBracketToken($escaped, 'company', $dealerFormat);

        if (! str_contains($escaped, '@') && ! str_contains($escaped, '#')) {
            return $escaped;
        }

        preg_match_all('/[@#]([A-Za-z0-9][A-Za-z0-9-]*)/u', $escaped, $matches);
        /** @var list<string> $uids */
        $uids = array_values(array_unique($matches[1] ?? []));
        if ($uids === []) {
            return $escaped;
        }

        $byUid = Main::query()
            ->whereIn('uid', $uids)
            ->get()
            ->keyBy(fn (Main $main): string => (string) $main->getUid());

        return (string) preg_replace_callback(
            '/[@#]([A-Za-z0-9][A-Za-z0-9-]*)/u',
            static function (array $m) use ($byUid): string {
                $uid = $m[1];
                $main = $byUid->get($uid);
                if ($main === null) {
                    return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
                }

                $url = MainResource::getUrl('view', ['record' => $main]);
                $text = htmlspecialchars(__('Aanvraag').': '.$uid, ENT_QUOTES, 'UTF-8');
                $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

                return '<a href="'.$href.'" class="fi-chat-inline-link" target="_blank" rel="noopener noreferrer">'.$text.'</a>';
            },
            $escaped
        );
    }

    /**
     * Klanten/dealers met een zichtbare naam (geen lege `name` én geen persoonsnaam).
     */
    private static function chatMentionCustomersQuery(): Builder
    {
        return Customer::query()->where(function (Builder $w): void {
            $w->where(function (Builder $n): void {
                $n->whereNotNull('name')->where('name', '!=', '');
            })->orWhere(function (Builder $p): void {
                $p->where(function (Builder $fn): void {
                    $fn->whereNotNull('first_name')->where('first_name', '!=', '');
                })->orWhere(function (Builder $ln): void {
                    $ln->whereNotNull('last_name')->where('last_name', '!=', '');
                });
            });
        });
    }

    /**
     * @param  callable(int, Collection<int, Note|Customer>): string  $format
     */
    private static function linkifyBracketToken(string $escaped, string $kind, callable $format): string
    {
        $pattern = '/\[\['.preg_quote($kind, '/').':(\d+)\]\]/';
        preg_match_all($pattern, $escaped, $matches);
        if ($matches[1] === []) {
            return $escaped;
        }

        $ids = array_values(array_unique(array_map(static fn (string $v): int => (int) $v, $matches[1])));
        $model = match ($kind) {
            'notitie', 'note' => Note::class,
            'klant', 'customer', 'dealer', 'company' => Customer::class,
            default => null,
        };
        if ($model === null) {
            return $escaped;
        }

        /** @var Collection<int, Note|Customer> $records */
        $records = $model::query()->whereIn('id', $ids)->get()->keyBy(fn ($m): int => (int) $m->getKey());

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($format, $records): string {
                $id = (int) $m[1];

                return $format($id, $records);
            },
            $escaped
        );
    }

    protected function scrollChatMessagesToEnd(): void
    {
        $this->js(<<<'JS'
            requestAnimationFrame(() => {
                const el = document.querySelector('.fi-page-chat .fi-chat-messages');
                if (el) {
                    el.scrollTop = el.scrollHeight;
                }
            });
        JS);
    }

    protected function getLastDisplayedMessageId(): ?int
    {
        if ($this->messageRows === []) {
            return null;
        }

        $key = array_key_last($this->messageRows);
        if ($key === null) {
            return null;
        }

        $last = $this->messageRows[$key];
        if (! is_array($last) || ! isset($last['id'])) {
            return null;
        }

        return (int) $last['id'];
    }

    protected function loadSidebarUsers(): void
    {
        $search = trim($this->userSearch);

        $unreadBySender = ChatMessage::unreadCountsBySenderForRecipient(Auth::id());

        $this->sidebarUserRows = User::query()
            ->where('id', '!=', Auth::id())
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($q2) use ($search): void {
                    $like = '%'.$search.'%';
                    $q2->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (User $user) use ($unreadBySender): array {
                $id = $user->getId();

                return [
                    'id' => $id,
                    'name' => $user->name,
                    'avatar_url' => Filament::getUserAvatarUrl($user),
                    'last_online_at' => $user->last_online_at?->toIso8601String(),
                    'is_online' => $user->isConsideredOnline(),
                    'unread_count' => (int) ($unreadBySender[$id] ?? 0),
                ];
            })
            ->all();

        if ($this->selectedUserId !== null) {
            $selectedRow = collect($this->sidebarUserRows)->firstWhere('id', $this->selectedUserId);
            if ($selectedRow !== null) {
                $this->selectedUserIsOnline = (bool) ($selectedRow['is_online'] ?? false);
            } else {
                $selected = User::query()->find($this->selectedUserId);
                $this->selectedUserIsOnline = $selected?->isConsideredOnline() ?? false;
            }
        }
    }

    protected function loadMessages(): void
    {
        if ($this->selectedUserId === null) {
            $this->messageRows = [];

            return;
        }

        $messages = ChatMessage::query()
            ->betweenUsers(Auth::id(), $this->selectedUserId)
            ->orderBy('created_at')
            ->get();

        $now = time();

        foreach ($messages as $message) {
            $id = (int) $message->getKey();
            $incomingUnread = (int) $message->getAttribute('to_user_id') === Auth::id()
                && $message->getAttribute('read_at') === null;

            if ($incomingUnread && ! isset($this->unreadHighlightUntil[$id])) {
                $this->unreadHighlightUntil[$id] = $now + 5;
            }
        }

        ChatMessage::markConversationRead(Auth::id(), $this->selectedUserId);

        $this->unreadHighlightUntil = array_filter(
            $this->unreadHighlightUntil,
            static fn (int $until): bool => $until > $now
        );

        $messageIds = $messages->modelKeys();
        $this->unreadHighlightUntil = array_intersect_key(
            $this->unreadHighlightUntil,
            array_flip(array_map('intval', $messageIds))
        );

        $rows = [];
        $previousCreatedAt = null;

        foreach ($messages as $message) {
            $createdAt = $message->getAttribute('created_at');
            $created = $createdAt instanceof Carbon ? $createdAt : Carbon::parse((string) $createdAt);
            $showDivider = $previousCreatedAt === null
                || abs($created->diffInSeconds($previousCreatedAt)) >= self::TIMESTAMP_DIVIDER_GAP_SECONDS;

            $id = (int) $message->getKey();
            $showUnreadHighlight = isset($this->unreadHighlightUntil[$id]) && $now < $this->unreadHighlightUntil[$id];

            $rawContent = (string) $message->getAttribute('content');

            $rows[] = [
                'id' => $id,
                'from_user_id' => (int) $message->getAttribute('from_user_id'),
                'content' => $rawContent,
                'content_html' => self::escapeAndLinkifyChatReferences($rawContent),
                'created_at' => $created->toIso8601String(),
                'show_day_divider' => $showDivider,
                'divider_label' => $created->translatedFormat('j F Y H:i'),
                'show_unread_highlight' => $showUnreadHighlight,
            ];
            $previousCreatedAt = $created;
        }

        $this->messageRows = $rows;
    }

    public static function canAccess(): bool
    {
        return Auth::check();
    }
}

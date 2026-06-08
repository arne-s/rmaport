<?php

use App\Models\ChatMessage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function getUnreadCountProperty(): int
    {
        if (! Auth::check()) {
            return 0;
        }

        return ChatMessage::unreadCountForUser(Auth::id());
    }
};
?>

<div class="fi-topbar-chat-btn-ctn" @chat-message-received.window="$wire.$refresh()">
    @auth
        <x-filament::icon-button
            tag="a"
            href="{{ \App\Filament\Pages\Chat::getUrl() }}"
            :badge="$this->unreadCount > 0 ? $this->unreadCount : null"
            color="gray"
            :icon="Heroicon::OutlinedChatBubbleLeftRight"
            icon-size="lg"
            label="Chat"
            class="fi-topbar-chat-btn"
            wire:navigate
        />
    @endauth
</div>

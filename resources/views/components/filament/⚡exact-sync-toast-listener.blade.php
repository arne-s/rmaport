<?php

use App\Models\AppSyncMessage;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $exactSyncPollActive = false;

    public int $exactSyncPollStartedAt = 0;

    private const EXACT_SYNC_POLL_MAX_SECONDS = 90;

    public function mount(): void
    {
        if (! Auth::check()) {
            return;
        }

        if (session()->pull(AppSyncMessage::SESSION_DEFERRED_EXACT_SYNC_TOAST_POLLING, false)) {
            $this->startExactSyncToastPolling();
        }
    }

    #[On('start-exact-sync-toast-polling')]
    public function startExactSyncToastPolling(): void
    {
        if (! Auth::check()) {
            return;
        }

        $this->exactSyncPollActive = true;
        $this->exactSyncPollStartedAt = time();

        $this->js(<<<'JS'
            (() => {
                if (window.__exactSyncAppMessagesInterval) {
                    clearInterval(window.__exactSyncAppMessagesInterval);
                }
                if (window.__exactSyncAppMessagesTimeout) {
                    clearTimeout(window.__exactSyncAppMessagesTimeout);
                }
                $wire.pollExactSyncToasts();
                window.__exactSyncAppMessagesInterval = setInterval(() => {
                    $wire.pollExactSyncToasts();
                }, 2000);
                window.__exactSyncAppMessagesTimeout = setTimeout(() => {
                    if (window.__exactSyncAppMessagesInterval) {
                        clearInterval(window.__exactSyncAppMessagesInterval);
                        window.__exactSyncAppMessagesInterval = null;
                    }
                }, 93000);
            })()
        JS);
    }

    public function pollExactSyncToasts(): void
    {
        if (! $this->exactSyncPollActive) {
            return;
        }

        if (time() - $this->exactSyncPollStartedAt > self::EXACT_SYNC_POLL_MAX_SECONDS) {
            $this->exactSyncPollActive = false;

            return;
        }

        $userId = Auth::id();
        if ($userId === null) {
            return;
        }

        $displaySince = now()->subMinutes(AppSyncMessage::DISPLAY_MAX_AGE_MINUTES);

        AppSyncMessage::query()
            ->where('user_id', $userId)
            ->whereIn('kind', AppSyncMessage::exactSyncKinds())
            ->whereNull('consumed_at')
            ->where('created_at', '<', $displaySince)
            ->update(['consumed_at' => now()]);

        $messages = AppSyncMessage::query()
            ->where('user_id', $userId)
            ->whereIn('kind', AppSyncMessage::exactSyncKinds())
            ->whereNull('consumed_at')
            ->where('created_at', '>=', $displaySince)
            ->orderBy('id')
            ->get();

        foreach ($messages as $message) {
            $notification = Notification::make()
                ->title($message->title)
                ->body($message->body);

            if ($message->status === AppSyncMessage::STATUS_SUCCESS) {
                $notification->success();
            } else {
                $notification->danger()->persistent();
            }

            $notification->send();

            $message->forceFill(['consumed_at' => now()])->save();
        }
    }
};
?>

<div class="fi-exact-sync-toast-listener sr-only" aria-hidden="true"></div>

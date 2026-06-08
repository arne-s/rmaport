<?php

namespace App\Filament\Concerns;

use App\Models\AppSyncMessage;

trait DispatchesExactSyncToastPolling
{
    protected function requestExactSyncToastPolling(): void
    {
        if (! auth()->check()) {
            return;
        }

        $this->dispatch('start-exact-sync-toast-polling')->to('filament.exact-sync-toast-listener');
    }

    protected function requestExactSyncToastPollingAfterRedirect(): void
    {
        if (! auth()->check()) {
            return;
        }

        AppSyncMessage::flashDeferredExactSyncToastPolling();
    }
}

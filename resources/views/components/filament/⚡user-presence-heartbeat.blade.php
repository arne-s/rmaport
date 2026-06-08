<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function touch(): void
    {
        if (! Auth::check()) {
            return;
        }

        Auth::user()->touchLastOnline();
    }
};
?>

<div class="fi-user-presence-heartbeat">
    @auth
        <span
            x-data
            x-init="
                $wire.touch();
                setInterval(() => { if (! document.hidden) { $wire.touch() } }, 60000);
                document.addEventListener('visibilitychange', () => { if (! document.hidden) { $wire.touch() } });
            "
        ></span>
    @endauth
</div>

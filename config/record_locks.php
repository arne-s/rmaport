<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lock lifetime
    |--------------------------------------------------------------------------
    |
    | How long an edit lock remains valid without being refreshed. Locks are
    | extended while the user stays on the edit page (Livewire hydrate / poll).
    |
    */

    'ttl_seconds' => (int) env('RECORD_LOCK_TTL_SECONDS', 900),

    'refresh_poll_seconds' => (int) env('RECORD_LOCK_REFRESH_POLL_SECONDS', 60),

];

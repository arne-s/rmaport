<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Form import sync
    |--------------------------------------------------------------------------
    |
    | WordPress must have Gravity Forms REST API v2 enabled (Forms → Settings →
    | REST API). Create an Application Password for the service account; use
    | that value as the API-token in Stamgegevens → Formulier-import.
    |
    */

    'page_size' => (int) env('FORM_IMPORT_PAGE_SIZE', 100),

    'schedule_cron' => env('FORM_IMPORT_SCHEDULE_CRON', '*/5 * * * *'),

];

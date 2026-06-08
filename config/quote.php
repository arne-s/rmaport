<?php

$quoteDomainEnv = trim((string) env('QUOTE_DOMAIN', ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Public quote approval hostname
    |--------------------------------------------------------------------------
    |
    | Host only or full URL in QUOTE_DOMAIN; used with Route::domain().
    | Empty QUOTE_DOMAIN disables the dedicated quote routes.
    |
    | Appointment calendar files live at /fitting-{mainId}.ics, /delivery-{mainId}.ics,
    | /service-customer-{mainId}.ics, etc. (mainId = Main primary key). When this domain
    | is set, those routes are bound to it; otherwise they are registered on the default
    | app host so e-mail [calendar_link] still resolves (using APP_URL).
    |
    | Public PDFs (no auth): /{public_download_uuid}/orderbevestiging.pdf (verkooporder) and
    | /{public_download_uuid}/factuur.pdf (slot-, aanbetalings- of creditfactuur). UUID is stored
    | on the corresponding orders row when the related customer mail is sent.
    |
    */

    'domain' => $quoteDomainEnv === ''
        ? null
        : (parse_url(str_contains($quoteDomainEnv, '://') ? $quoteDomainEnv : 'https://'.$quoteDomainEnv, PHP_URL_HOST) ?: null),

];

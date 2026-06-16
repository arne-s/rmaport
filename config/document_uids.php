<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Quote UID (numeric)
    |--------------------------------------------------------------------------
    |
    | Fixed width (default 5 digits), e.g. 10268. Quote revisions use {@see BaseOrder::getUidFormatted()}; order revisions use {@see BaseOrder::getUidFormattedWithRevision()} in Financiële documenten only.
    |
    */
    'quote' => [
        'start' => (int) env('DOCUMENT_UID_QUOTE_START', 10000),
        'digits' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sales order UID (numeric)
    |--------------------------------------------------------------------------
    |
    | Fixed width (default 5 digits), e.g. 31053.
    |
    */
    'order' => [
        'start' => (int) env('DOCUMENT_UID_ORDER_START', 30000),
        'digits' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice UIDs (deposit, final, credit)
    |--------------------------------------------------------------------------
    |
    | Single numeric starting value (e.g. 26414309), then increments as an integer.
    | Digits only, no prefix; output is left-padded to at least the start value’s digit count.
    |
    */
    'invoice' => [
        'start' => (int) env('DOCUMENT_UID_INVOICE_START', 26400001),
    ],

    /*
    |--------------------------------------------------------------------------
    | Main (request) — minimum sequence for A-YYYY-NNNN
    |--------------------------------------------------------------------------
    */
    'main' => [
        'sequence_start' => (int) env('DOCUMENT_UID_MAIN_SEQUENCE_START', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Packing slip & delivery note (shared numeric id)
    |--------------------------------------------------------------------------
    |
    | Packing slips (afleverbon) and delivery notes (pakbon) share one incrementing sequence
    | (stored on {@see \App\Models\PackingSlip::$uid} and {@see \App\Models\DeliveryNote::$uid}).
    | PDF filenames use afleverbon-* / delivery-note-* prefixes respectively.
    |
    */
    'packing_slip' => [
        'start' => (int) env('DOCUMENT_UID_PACKING_SLIP_START', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | RMA number (numeric)
    |--------------------------------------------------------------------------
    |
    | Fixed width (default 8 digits), e.g. 00000001.
    |
    */
    'rma' => [
        'start' => (int) env('DOCUMENT_UID_RMA_START', 1),
        'digits' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Import batch UID (prefixed)
    |--------------------------------------------------------------------------
    |
    | Prefix IM- with fixed-width numeric suffix, e.g. IM-0000001, IM-0413113.
    |
    */
    'import' => [
        'prefix' => env('DOCUMENT_UID_IMPORT_PREFIX', 'IM-'),
        'start' => (int) env('DOCUMENT_UID_IMPORT_START', 1),
        'digits' => (int) env('DOCUMENT_UID_IMPORT_DIGITS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Import export UID (prefixed)
    |--------------------------------------------------------------------------
    |
    | Prefix EX- with fixed-width numeric suffix, e.g. EX-0000001.
    |
    */
    'export' => [
        'prefix' => env('DOCUMENT_UID_EXPORT_PREFIX', 'EX-'),
        'start' => (int) env('DOCUMENT_UID_EXPORT_START', 1),
        'digits' => (int) env('DOCUMENT_UID_EXPORT_DIGITS', 7),
    ],
];

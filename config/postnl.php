<?php

return [

    'api_key' => env('POSTNL_API_KEY'),

    'api_url' => env('POSTNL_API_URL', 'https://api-sandbox.postnl.nl'),

    'customer_number' => env('POSTNL_CUSTOMER_NUMBER', '10575473'),

    'collection_location' => env('POSTNL_COLLECTION_LOCATION', '100548'),

    /*
    |--------------------------------------------------------------------------
    | EU shipments (Pakketten aanmelden)
    |--------------------------------------------------------------------------
    */
    'eu' => [
        'customer_code'  => env('POSTNL_CUSTOMER_CODE_EU', 'COSK'),
        'barcode_type'   => '3S',
        'barcode_range'  => '00000000-99999999',
    ],

    /*
    |--------------------------------------------------------------------------
    | Non-EU shipments
    |--------------------------------------------------------------------------
    */
    'non_eu' => [
        'customer_code'  => env('POSTNL_CUSTOMER_CODE_NON_EU', 'CC6210'),
        'barcode_type'   => 'CD',
        'barcode_range'  => '0000-9999',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender / shipper address (shown on label)
    |--------------------------------------------------------------------------
    */
    'sender' => [
        'company'     => env('POSTNL_SENDER_COMPANY', 'RD Mobility B.V.'),
        'street'      => env('POSTNL_SENDER_STREET', 'Schieweg'),
        'house_nr'    => env('POSTNL_SENDER_HOUSENR', '87'),
        'postcode'    => env('POSTNL_SENDER_POSTCODE', '2627AT'),
        'city'        => env('POSTNL_SENDER_CITY', 'DELFT'),
        'country'     => 'NL',
    ],

];

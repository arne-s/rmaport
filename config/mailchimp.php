<?php

return [

    'key' => env('MAILCHIMP_API_KEY'),
    'data_center' => env('MAILCHIMP_DATA_CENTER'),
    'audience_id' => env('MAILCHIMP_AUDIENCE_ID'),

    /**
     * Audience tag name per segment key (must match an existing tag in the audience).
     */
    'tags' => [
        'customer_b2c' => env('MAILCHIMP_TAG_CUSTOMER_B2C', 'Particulier'),
        'customer_b2b_billing' => env('MAILCHIMP_TAG_CUSTOMER_B2B_BILLING', 'B2B (factuur)'),
        'customer_b2b_shipping' => env('MAILCHIMP_TAG_CUSTOMER_B2B_SHIPPING', 'B2B (locatie)'),
        'dealer_billing' => env('MAILCHIMP_TAG_DEALER_BILLING', 'Dealer (factuur)'),
        'dealer_shipping' => env('MAILCHIMP_TAG_DEALER_SHIPPING', 'Dealer (locatie)'),
        'uniek_sporten_billing' => env('MAILCHIMP_TAG_UNIEK_SPORTEN_BILLING', 'Uniek Sporten (factuur)'),
        'uniek_sporten_shipping' => env('MAILCHIMP_TAG_UNIEK_SPORTEN_SHIPPING', 'Uniek Sporten (locatie)'),
    ],
];

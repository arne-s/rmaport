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
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postcode' => [
        'key' => env('POSTCODE_API_KEY'),
        'secret' => env('POSTCODE_API_SECRET'),
    ],

    'mollie' => [
        'key' => env('MOLLIE_API_KEY'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'ors' => [
        'key' => env('ORS_API_KEY'),
    ],

    'mailchimp' => [
        'key' => env('MAILCHIMP_API_KEY'),
        'data_center' => env('MAILCHIMP_DATA_CENTER'),
        'audience_id' => env('MAILCHIMP_AUDIENCE_ID'),
    ],

    'microsoft' => [
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'mail_redirect' => env('MICROSOFT_MAIL_REDIRECT_URI'),
    ],
];

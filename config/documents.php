<?php

$uploadTypes = require __DIR__.'/document_upload_types.php';

$allMimeTypes = array_values(array_unique(array_merge(
    $uploadTypes['office_and_mail_mime_types'],
    $uploadTypes['image_mime_types'],
)));

return [
    /*
    |--------------------------------------------------------------------------
    | Default allowed MIME types for document uploads (office, mail, PDF, images)
    |--------------------------------------------------------------------------
    */
    'allowed_mime_types' => $allMimeTypes,

    /*
    |--------------------------------------------------------------------------
    | Accept attribute for file input (extensions + MIME types for browser)
    |--------------------------------------------------------------------------
    */
    'accept_attribute' => $uploadTypes['office_and_mail_accept'].','.$uploadTypes['image_accept'],

    /*
    |--------------------------------------------------------------------------
    | MIME types that can be opened in the preview modal (inline viewing)
    |--------------------------------------------------------------------------
    */
    'openable_mime_types' => [
        'application/pdf',
        'application/vnd.ms-outlook',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions that can be opened (for reference / display)
    |--------------------------------------------------------------------------
    */
    'openable_extensions' => ['pdf', 'msg', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],

    /*
    |--------------------------------------------------------------------------
    | Images-only block (e.g. Afbeeldingen): allowed MIME types and accept
    |--------------------------------------------------------------------------
    */
    'images_allowed_mime_types' => $uploadTypes['image_mime_types'],
    'images_accept_attribute' => $uploadTypes['image_accept'],

    /*
    |--------------------------------------------------------------------------
    | Cache duration for parsed .msg previews (seconds)
    |--------------------------------------------------------------------------
    | Parsing .msg files (especially with large attachments) can be slow.
    | Previews are built in the background after a .msg upload (MediaHasBeenAddedEvent) and stored here.
    | Set to 0 to disable caching.
    */
    'msg_preview_cache_seconds' => 3600,
];

<?php

$uploadTypes = require __DIR__.'/document_upload_types.php';

$allMimeTypes = array_values(array_unique(array_merge(
    $uploadTypes['office_and_mail_mime_types'],
    $uploadTypes['image_mime_types'],
)));

return [
    'allowed_mime_types' => $allMimeTypes,
    'accept_attribute' => $uploadTypes['office_and_mail_accept'].','.$uploadTypes['image_accept'],
];

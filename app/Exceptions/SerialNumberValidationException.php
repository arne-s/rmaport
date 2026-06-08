<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ValidationException wrapper for serial number errors (e.g. SerialNumberAlreadyInUseException).
 * Extends ValidationException so the form shows the field error, but can be caught specifically
 * to show a tailored notification.
 */
class SerialNumberValidationException extends ValidationException
{
    public function __construct(
        SerialNumberOwnerMismatchException|SerialNumberAlreadyInUseException $e,
    ) {
        $validator = Validator::make([], []);
        $validator->errors()->add('serial_number_sync', $e->getMessage());

        parent::__construct($validator);
    }
}

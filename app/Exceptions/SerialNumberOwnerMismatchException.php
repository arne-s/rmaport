<?php

namespace App\Exceptions;

use App\Models\SerialNumber;

class SerialNumberOwnerMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly SerialNumber $serialNumber,
        string $message = 'Dit serienummer is gekoppeld aan een andere klant.',
    ) {
        parent::__construct($message);
    }
}

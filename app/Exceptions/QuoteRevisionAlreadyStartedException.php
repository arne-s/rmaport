<?php

namespace App\Exceptions;

use Exception;

class QuoteRevisionAlreadyStartedException extends Exception
{
    public function __construct(
        string $message = 'Deze offerte is al herzien.',
        public readonly ?string $startedByUserName = null,
    ) {
        parent::__construct($message);
    }
}

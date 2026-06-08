<?php

namespace App\Exceptions;

use Exception;

class TransactionalActionValidationException extends Exception
{
    public function __construct(string $class, string $message = '')
    {
        if ($message) {
            // Use custom message if provided
            $fullMessage = $message;
        } else {
            // Use default message if no custom message
            $fullMessage = "Validation failed for transactional action '{$class}'.";
        }
        
        parent::__construct($fullMessage);
    }
}


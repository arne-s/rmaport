<?php

namespace App\Exceptions;

use Exception;

class DuplicateTransactionalActionException extends Exception
{
    public function __construct(string $class, array $key)
    {
        $keyString = implode('|', $key);
        $message = "Transactional action '{$class}' with key '{$keyString}' has already been executed.";
        
        parent::__construct($message);
    }
}


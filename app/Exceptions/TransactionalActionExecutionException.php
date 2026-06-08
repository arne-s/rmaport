<?php

namespace App\Exceptions;

use Exception;

class TransactionalActionExecutionException extends Exception
{
    public function __construct(string $class, array $key, string $message = '')
    {
        $keyString = implode('|', $key);
        $defaultMessage = "Execution failed for transactional action '{$class}' with key '{$keyString}'.";
        $fullMessage = $message ? "{$defaultMessage} {$message}" : $defaultMessage;
        
        parent::__construct($fullMessage);
    }
}


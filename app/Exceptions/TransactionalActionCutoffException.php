<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Carbon;

class TransactionalActionCutoffException extends Exception
{
    public function __construct(string $class, Carbon $cutoffTimestamp, ?Carbon $verifyTime = null)
    {
        if ($verifyTime) {
            $message = "Transactional action '{$class}' cannot be executed because verifyTime ({$verifyTime->toDateTimeString()}) is before the cutoff timestamp: {$cutoffTimestamp->toDateTimeString()}";
        } else {
            $message = "Transactional action '{$class}' cannot be executed because it would occur before the cutoff timestamp: {$cutoffTimestamp->toDateTimeString()}";
        }
        
        parent::__construct($message);
    }
}


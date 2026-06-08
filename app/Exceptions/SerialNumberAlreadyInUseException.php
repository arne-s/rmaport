<?php

namespace App\Exceptions;

use App\Models\Order\Order;
use App\Models\Order\Main;
use App\Models\SerialNumber;

class SerialNumberAlreadyInUseException extends \RuntimeException
{
    public function __construct(
        public readonly SerialNumber $serialNumber,
        public readonly Main|Order $existingRecord,
    ) {
        $uid = $existingRecord->getUidFormatted();
        parent::__construct("Dit serienummer is al in gebruik bij aanvraag {$uid}.");
    }
}

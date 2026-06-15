<?php

namespace App\Enums\Import;

enum ImportRowValidationStatus: string
{
    case New = 'new';
    case Existing = 'existing';
    case Invalid = 'invalid';
}

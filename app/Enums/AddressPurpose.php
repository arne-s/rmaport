<?php

namespace App\Enums;

enum AddressPurpose: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';
}

<?php

namespace App\Enums;

enum CustomerAddressType: string
{
    case Billing = 'billing';
    case Shipping = 'shipping';
}

<?php

namespace App\Enums;

use App\Models\Customer;

enum CompanyDocumentsTabScope: string
{
    case InvoiceOnly = 'invoice_only';
    case ShippingOnly = 'shipping_only';
    case AllGlobal = 'all_global';

    public static function forCustomer(?Customer $customer): self
    {
        return self::InvoiceOnly;
    }

    public function getEmptyStateHeading(): string
    {
        return match ($this) {
            self::InvoiceOnly => 'Geen facturen',
            self::ShippingOnly => 'Geen documenten',
            self::AllGlobal => 'Geen documenten',
        };
    }
}

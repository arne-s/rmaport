<?php
namespace App\Enums;

enum PurchaseInvoiceRowType
{
    case OrderRow; // Order row without invoice
    case OrderRowParent; // Parent order row without invoices
    case OrderRowChild; // Child order row without invoice
    case InvoiceRow; // Invoice row
    case InvoiceRowParent; // Parent invoice row with invoices
    case InvoiceRowChild; // Child invoice row with invoices
}

<?php

namespace App\Enums;

enum InvoiceCaption: string
{
    case DepositInvoice = 'deposit_invoice';
    case RegularInvoice = 'regular_invoice';
    case FinalInvoice   = 'final_invoice';

    /** Label for financial documents list and PDF title. */
    public function getLabel(): string
    {
        return match ($this) {
            self::DepositInvoice => 'Aanbetalingsfactuur',
            self::RegularInvoice => 'Factuur',
            self::FinalInvoice   => 'Slotfactuur',
        };
    }

    /** Label for the type dropdown on EditInvoice. */
    public function getFormLabel(): string
    {
        return match ($this) {
            self::DepositInvoice => 'Aanbetalingsfactuur',
            self::RegularInvoice => 'Reguliere factuur',
            self::FinalInvoice   => 'Slotfactuur',
        };
    }

    /** @return array<string, string> */
    public static function formOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c): array => [$c->value => $c->getFormLabel()])
            ->toArray();
    }
}

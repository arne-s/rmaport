<?php

namespace App\Enums;

enum OrderType: string
{
    case Main = 'main';
    case Quote = 'quote';
    case Order = 'order';
    case StockOrder = 'stock_order';
    case DepositInvoice = 'deposit_invoice';
    case Invoice = 'invoice';
    case CreditInvoice = 'credit_invoice';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Main => 'Aanvraag',
            self::Quote => 'Offerte',
            self::Order => 'Order',
            self::StockOrder => 'Inkooporder',
            self::DepositInvoice => 'Aanbetalingsfactuur',
            self::Invoice => 'Slotfactuur',
            self::CreditInvoice => 'Creditfactuur',
            default => null,
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }
}

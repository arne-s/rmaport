<?php

namespace App\Support;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use App\Models\Setting;
use App\Settings\Definitions\EnabledDisabledSetting;

final class InvoiceReminderSettings
{
    /**
     * @return array<string, string>
     */
    public static function segmentLabels(): array
    {
        return [
            CustomerType::B2C->value => 'Particulier',
            CustomerType::B2B->value => 'B2B',
        ];
    }

    public static function settingUidForSegment(string $segment): string
    {
        return 'mail.invoice_reminders.' . $segment . '.enabled';
    }

    public static function isEnabledForSegment(string $segment): bool
    {
        $value = Setting::get(self::settingUidForSegment($segment), EnabledDisabledSetting::ENABLED);

        return EnabledDisabledSetting::isEnabled($value);
    }

    public static function isEnabledForOrder(BaseOrder $order): bool
    {
        return self::isEnabledForSegment($order->getBillingCustomerSegmentKey());
    }

    public static function resolveSegmentKey(?Customer $invoiceCustomer): string
    {
        if ($invoiceCustomer === null) {
            return CustomerType::B2C->value;
        }

        return match ($invoiceCustomer->getType()) {
            CustomerType::B2C => CustomerType::B2C->value,
            CustomerType::B2B, CustomerType::AV => CustomerType::B2B->value,
            default => CustomerType::B2C->value,
        };
    }
}

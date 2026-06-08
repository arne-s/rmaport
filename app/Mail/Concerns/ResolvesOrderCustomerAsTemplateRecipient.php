<?php

namespace App\Mail\Concerns;

use App\Models\Customer;
use App\Models\Order\BaseOrder;

/**
 * Maps [user_name], [user_first_name], [user_last_name], [user_email] to the order customer,
 * not the To user configured on the e-mail template in admin.
 */
trait ResolvesOrderCustomerAsTemplateRecipient
{
    /**
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        return $this->formatCustomerTemplateRecipientVars($this->resolveOrderCustomerForTemplateRecipient());
    }

    /**
     * @return array{user_name: string, user_first_name: string, user_last_name: string, user_email: string}
     */
    protected function formatCustomerTemplateRecipientVars(?Customer $customer): array
    {
        if ($customer === null) {
            return [
                'user_name' => '',
                'user_first_name' => '',
                'user_last_name' => '',
                'user_email' => '',
            ];
        }

        $displayName = $customer->getName() ?? '';

        return [
            'user_name' => $displayName,
            'user_first_name' => $displayName,
            'user_last_name' => (string) ($customer->getLastName() ?? ''),
            'user_email' => (string) ($customer->getEmail() ?? ''),
        ];
    }

    protected function resolveOrderCustomerForTemplateRecipient(): ?Customer
    {
        $order = $this->resolveOrderForCustomerRecipient();

        if ($order === null) {
            return null;
        }

        $order->loadMissing(['customer', 'billingCustomer']);

        return $order->customer ?? $order->billingCustomer;
    }

    protected function resolveOrderForCustomerRecipient(): ?BaseOrder
    {
        if (! property_exists($this, 'order')) {
            return null;
        }

        $order = $this->order;

        return $order instanceof BaseOrder ? $order : null;
    }
}

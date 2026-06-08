<?php

namespace App\Mail\Concerns;

use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\User;

/**
 * Maps [user_name], [user_first_name], [user_last_name], [user_email] to the order advisor,
 * not the To user configured on the e-mail template in admin.
 */
trait ResolvesOrderAdvisorAsTemplateRecipient
{
    /**
     * @return array<string, string>
     */
    public function getTemplateRecipientVars(): array
    {
        return $this->formatAdvisorTemplateRecipientVars($this->resolveOrderAdvisor());
    }

    /**
     * @return array{user_name: string, user_first_name: string, user_last_name: string, user_email: string}
     */
    protected function formatAdvisorTemplateRecipientVars(?User $advisor): array
    {
        if ($advisor === null) {
            return [
                'user_name' => '',
                'user_first_name' => '',
                'user_last_name' => '',
                'user_email' => '',
            ];
        }

        return [
            'user_name' => $advisor->getName() ?? '',
            'user_first_name' => $advisor->getFirstName() ?? $advisor->getName() ?? '',
            'user_last_name' => (string) ($advisor->getLastName() ?? ''),
            'user_email' => (string) ($advisor->getEmail() ?? ''),
        ];
    }

    protected function resolveOrderAdvisor(): ?User
    {
        $order = $this->resolveOrderForAdvisorRecipient();

        if ($order === null) {
            return null;
        }

        $order->loadMissing(['advisor', 'main.advisor']);

        if ($order instanceof Main) {
            return $order->advisor;
        }

        return $order->advisor ?? $order->main?->advisor;
    }

    protected function resolveOrderForAdvisorRecipient(): ?BaseOrder
    {
        if (! property_exists($this, 'order')) {
            return null;
        }

        $order = $this->order;

        return $order instanceof BaseOrder ? $order : null;
    }
}

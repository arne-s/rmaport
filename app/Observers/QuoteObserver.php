<?php

namespace App\Observers;

use App\Actions\SendQuoteCancelledMailAction;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Quote;
use Throwable;

class QuoteObserver
{
    public function created(Quote $quote): void
    {
        $this->syncMainAdvisorWhenMissing($quote);
    }

    public function deleting(Quote $quote): void
    {
        if ($quote->main_id !== null) {
            $quote->main?->changeOrderStatus(OrderStatus::FittingReady);
        }
    }

    /**
     * @throws Throwable
     */
    public function updated(Quote $quote): void
    {
        $this->syncMainAdvisorWhenMissing($quote);

        $oldStatus = $quote->getOriginal('status');
        $newStatus = $quote->status;

        $oldValue = $oldStatus instanceof \BackedEnum ? $oldStatus->value : (string) ($oldStatus ?? '');
        $newValue = $newStatus instanceof \BackedEnum ? $newStatus->value : (string) ($newStatus ?? '');

        if ($newValue === OrderGeneralStatus::Cancelled->value && $quote->main_id !== null) {
            app(SendQuoteCancelledMailAction::class)->execute($quote);

            $main = $quote->main;
            if ($main !== null) {
                $main->setCancelComment($quote->getCancelComment());
                $main->saveQuietly();
                $main->changeOrderStatus(OrderStatus::QuoteCancelled);
                $main->changeOrderStatus(OrderStatus::Cancelled);
            }
            return;
        }

        if ($oldValue === OrderGeneralStatus::Draft->value) {
            return;
        }
        if ($newValue !== OrderGeneralStatus::Draft->value) {
            return;
        }

        $quote->main?->changeOrderStatus(OrderStatus::QuoteConcept);
    }

    private function syncMainAdvisorWhenMissing(Quote $quote): void
    {
        if ($quote->main_id === null) {
            return;
        }

        $advisorId = $quote->advisor_id;
        if ($advisorId === null) {
            return;
        }

        $main = $quote->main;
        if ($main === null || $main->advisor_id !== null) {
            return;
        }

        $main->advisor_id = $advisorId;
        $main->saveQuietly();
    }
}

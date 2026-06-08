<?php

namespace App\Filament\Resources\OrderResource\Widgets\Concerns;

use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Models\Order\Main;

trait LocksProductTabWhenMainOrderPhase
{
    protected function isProductTabInteractionLocked(): bool
    {
        $record = $this->record ?? null;
        if (! $record instanceof Main) {
            return false;
        }

        if ($record->getSubtype() === OrderSubtype::Part) {
            return false;
        }

        return OrderStatus::locksProductTabInteractions($record->getOrderStatus());
    }

    protected function canInteractWithPurchaseTabProducts(): bool
    {
        return ! $this->isProductTabInteractionLocked();
    }
}

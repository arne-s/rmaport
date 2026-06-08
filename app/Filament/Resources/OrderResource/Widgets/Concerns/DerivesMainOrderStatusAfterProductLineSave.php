<?php

namespace App\Filament\Resources\OrderResource\Widgets\Concerns;

use App\Enums\OrderProductStatus;
use App\Models\Order\Main;
use App\Models\OrderProduct;

trait DerivesMainOrderStatusAfterProductLineSave
{
    protected function deriveMainOrderStatusAfterProductLineSave(): void
    {
        if (! $this->record instanceof Main) {
            return;
        }

        $this->record->applyDerivedOrderStatusFromOrderProducts(
            fn (OrderProduct $orderProduct): ?OrderProductStatus => $orderProduct->getStatus(),
        );
    }
}

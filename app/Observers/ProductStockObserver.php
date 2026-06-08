<?php

namespace App\Observers;

use App\Actions\SendLowStockAlertMailAction;
use App\Models\ProductStock;
use App\Support\LowStockAlertContext;

class ProductStockObserver
{
    public function updated(ProductStock $stock): void
    {
        if (! $this->shouldSendAlert($stock)) {
            return;
        }

        app(SendLowStockAlertMailAction::class)->execute(
            $stock,
            LowStockAlertContext::get(),
        );
    }

    private function shouldSendAlert(ProductStock $stock): bool
    {
        $prevAvailable = (int) $stock->getOriginal('physical_stock') - (int) $stock->getOriginal('reserved_stock');
        $newAvailable = $stock->calculateAvailableStock();
        $threshold = $stock->getMinThreshold();

        if ($newAvailable >= $prevAvailable) {
            return false;
        }

        if ($prevAvailable <= $threshold) {
            return false;
        }

        if ($newAvailable > $threshold) {
            return false;
        }

        if (! $stock->product?->getIsStockEnabled()) {
            return false;
        }

        return true;
    }
}

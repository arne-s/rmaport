<?php

use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;

it('loads purchase order models required by the supplier documents widget', function (): void {
    expect(class_exists(PurchaseOrder::class))->toBeTrue()
        ->and(class_exists(StockOrder::class))->toBeTrue();
});

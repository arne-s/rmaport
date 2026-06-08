<?php

namespace App\Actions;

use App\Mail\LowStockAlertMail;
use App\Models\OrderProduct;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendLowStockAlertMailAction
{
    public function execute(ProductStock $stock, ?OrderProduct $orderProduct = null): bool
    {
        if (! LowStockAlertMail::hasConfiguredRecipients()) {
            Log::warning('LowStockAlertMail skipped: template has no deliverable recipients.', [
                'product_stock_id' => $stock->getKey(),
                'product_id' => $stock->getProductId(),
            ]);

            return false;
        }

        Mail::send(new LowStockAlertMail($stock, $orderProduct));

        return true;
    }
}

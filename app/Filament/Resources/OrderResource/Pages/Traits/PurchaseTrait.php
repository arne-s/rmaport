<?php

namespace App\Filament\Resources\OrderResource\Pages\Traits;

use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\InventoryService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

trait PurchaseTrait
{

}

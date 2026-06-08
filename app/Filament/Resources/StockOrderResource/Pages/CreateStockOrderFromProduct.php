<?php

namespace App\Filament\Resources\StockOrderResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\StockOrderResource;
use App\Filament\Support\PurchaseAuthorization;
use App\Models\Product;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

class CreateStockOrderFromProduct extends Page
{
    protected static string $resource = StockOrderResource::class;

    protected static ?string $title = 'Inkooporder';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $product): void
    {
        abort_unless(PurchaseAuthorization::canManage(), 403);

        $productModel = Product::query()->find($product);

        if ($productModel === null || ! ProductResource::canCreateStockOrderForProduct($productModel)) {
            $this->redirect(StockOrderResource::getUrl('create'));

            return;
        }

        $stockOrder = ProductResource::createStockOrderForProducts(
            (int) $productModel->supplier_id,
            Collection::make([$productModel]),
        );

        $this->redirect(StockOrderResource::getUrl('edit', ['record' => $stockOrder->getId()]));
    }
}

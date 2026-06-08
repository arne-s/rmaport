<?php

namespace App\Services;

use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Exceptions\OrderOutOfStockException;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Order\Order;
use App\Models\OrderProduct;
use App\Support\LowStockAlertContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    protected function getLockedStockRow(Product $product): ProductStock
    {
        // Try to lock an existing row
        $productId = $product->getId();
        $stock = ProductStock::where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        // Create if missing
        ProductStock::firstOrCreate(
            ['product_id' => $productId],
            [
                'physical_stock' => 0,
                'reserved_stock' => 0,
                'min_threshold'  => 0,
                'allow_backorder' => true,
            ]
        );

        return ProductStock::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Reserve stock for an order.
     * If backorders are allowed, available stock may go below zero.
     */
    public function reserveForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->orderProducts()->get() as $orderProduct) {
                /** @var OrderProduct $orderProduct */
                $product = $orderProduct->getProduct();
                $qty = $orderProduct->getQty();

                // Only reserve Make-to-Stock products
                if ($orderProduct->getFulfillmentType() !== FulfillmentType::MakeToStock) {
                    // If Make-to-Order product, set status to Ordered
                    if ($orderProduct->getFulfillmentType() === FulfillmentType::MakeToOrder) {
                        $orderProduct->setStatus(OrderProductStatus::Purchased);
                        $orderProduct->save();
                    }
                    continue;
                }

                $stock = $this->getLockedStockRow($product);

                $allowBackorder = $stock->getAllowBackorder() ?? true;
                if (!$allowBackorder && $stock->getAvailableStock() < $qty) {
                    throw new OrderOutOfStockException(
                        "Insufficient stock for product {$product->id}"
                    );
                }

                // Reserve stock
                $stock->setReservedStock($stock->getReservedStock() + $qty);
                LowStockAlertContext::set($orderProduct);
                try {
                    $stock->save();
                } finally {
                    LowStockAlertContext::clear();
                }

                // Update order product status
                if ($allowBackorder && $stock->calculateAvailableStock() < 0) {
                    // Available stock has to be calculated instead of using getAvailableStock() (available_stock is a generated DB column)
                    // because getAvailableStock() has not been updated yet since this logic is inside a transaction.
                    $orderProduct->setStatus(OrderProductStatus::BackOrder);
                } else {
                    $orderProduct->setStatus(OrderProductStatus::InStock);
                }
                $orderProduct->save();

            }
        });
    }

    /**
     * Release reservation when an order is cancelled before shipping.
     */
    public function releaseReservationForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->orderProducts as $orderProduct) {
                $product = $orderProduct->getProduct();
                $qty = $orderProduct->getQty();

                $stock = ProductStock::where('product_id', $product->getId())
                    ->lockForUpdate()
                    ->first();

                if (!$stock) {
                    continue;
                }

                $stock->setReservedStock($stock->getReservedStock() - $qty);
                if ($stock->getReservedStock() < 0) {
                    $stock->setReservedStock(0);
                }
                $stock->save();
            }
        });
    }

    /**
     * Warehouse receives a stock order.
     * Physical stock goes up, reservation does not change.
     */
    public function deliverOrderProduct(OrderProduct $orderProduct): void
    {
        DB::transaction(function () use ($orderProduct) {
            $product = $orderProduct->getProduct();
            $qty = $orderProduct->getQty();

            $stock = $this->getLockedStockRow($product);

            $stock->setPhysicalStock($stock->getPhysicalStock() + $qty);
            $stock->save();

            StockMovement::create([
                'product_id' => $product->getId(),
                'quantity'   => $qty,
                'type'       => 'purchase',
                'ref_table'  => 'order_products',
                'ref_id'     => $orderProduct->getId(),
            ]);
        });
    }

    /**
     * Pick a specific product for an order.
     * Physical stock and reservation both go down for the given product.
     */
    public function pickOrderProduct(OrderProduct $orderProduct): void
    {
        DB::transaction(function () use ($orderProduct) {
            $product = $orderProduct->getProduct();
            $qty = $orderProduct->getQty();

            $stock = ProductStock::where('product_id', $product->getId())
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                throw new RuntimeException(
                    "No stock row found for product {$product->getId()}"
                );
            }

            if ($stock->getPhysicalStock() < $qty) {
                throw new RuntimeException(
                    "Physical stock not sufficient for product {$product->getId()}"
                );
            }

            $stock->setPhysicalStock($stock->getPhysicalStock() - $qty);
            $stock->setReservedStock($stock->getReservedStock() - $qty);

            $stock->save();

            StockMovement::create([
                'product_id' => $product->id,
                'quantity'   => -$qty,
                'type'       => 'sale',
                'ref_table'  => 'order_products',
                'ref_id'     => $orderProduct->id,
            ]);
        });
    }

    /**
     * Undo pick: add physical and reserved stock back for the given order product.
     * Use after pickOrderProduct() (order flow).
     */
    public function unpickOrderProduct(OrderProduct $orderProduct): void
    {
        DB::transaction(function () use ($orderProduct) {
            $product = $orderProduct->getProduct();
            $qty = $orderProduct->getQty();

            $stock = ProductStock::where('product_id', $product->getId())
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                return;
            }

            $stock->setPhysicalStock($stock->getPhysicalStock() + $qty);
            $stock->setReservedStock($stock->getReservedStock() + $qty);
            $stock->save();

            StockMovement::create([
                'product_id' => $product->id,
                'quantity'   => $qty,
                'type'       => 'return_in',
                'ref_table'  => 'order_products',
                'ref_id'     => $orderProduct->id,
            ]);
        });
    }

    /**
     * Pick from stock only (physical stock).
     */
    public function pickOrderProductFromStock(OrderProduct $orderProduct): void
    {
        DB::transaction(function () use ($orderProduct) {
            $product = $orderProduct->getProduct();
            $qty = $orderProduct->getQty();

            $stock = ProductStock::where('product_id', $product->getId())
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw new RuntimeException(
                    "No stock row found for product {$product->getId()}"
                );
            }

            if ($stock->getAvailableStock() < $qty) {
                throw new RuntimeException(
                    "Beschikbare voorraad niet voldoende voor product {$product->getId()}"
                );
            }

            $stock->setPhysicalStock($stock->getPhysicalStock() - $qty);
            LowStockAlertContext::set($orderProduct);
            try {
                $stock->save();
            } finally {
                LowStockAlertContext::clear();
            }

            StockMovement::create([
                'product_id' => $product->id,
                'quantity'   => -$qty,
                'type'       => 'sale',
                'ref_table'  => 'order_products',
                'ref_id'     => $orderProduct->id,
            ]);
        });
    }

    /**
     * Undo pick from stock: add physical stock back. For use when undoing
     * PickedStock on the Main product tab (after pickOrderProductFromStock).
     */
    public function unpickOrderProductFromStock(OrderProduct $orderProduct): void
    {
        DB::transaction(function () use ($orderProduct) {
            $product = $orderProduct->getProduct();
            $qty = $orderProduct->getQty();

            $stock = ProductStock::where('product_id', $product->getId())
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                return;
            }

            $stock->setPhysicalStock($stock->getPhysicalStock() + $qty);
            $stock->save();

            StockMovement::create([
                'product_id' => $product->id,
                'quantity'   => $qty,
                'type'       => 'return_in',
                'ref_table'  => 'order_products',
                'ref_id'     => $orderProduct->id,
            ]);
        });
    }

    /**
     * Book physical stock back in after a canceled main order line (Opboeken voorraad).
     */
    public function addDeliveredCanceledProductToStock(OrderProduct $orderProduct): void
    {
        $this->addCanceledProductToStock($orderProduct);
    }

    public function addCanceledProductToStock(OrderProduct $orderProduct): void
    {
        if ($orderProduct->getStatus() === OrderProductStatus::AddToStock) {
            return;
        }

        DB::transaction(function () use ($orderProduct) {
            $product = $orderProduct->getProduct();
            if ($product === null) {
                throw new RuntimeException(
                    "Geen product gekoppeld aan orderregel {$orderProduct->getId()}"
                );
            }

            $qty = $orderProduct->getQty();
            $stock = $this->getLockedStockRow($product);

            $stock->setPhysicalStock($stock->getPhysicalStock() + $qty);
            $stock->save();

            StockMovement::create([
                'product_id' => $product->getId(),
                'quantity' => $qty,
                'type' => 'return_in',
                'ref_table' => 'order_products',
                'ref_id' => $orderProduct->getId(),
            ]);
        });
    }

    /**
     * Pick an order.
     * Physical stock and reservation both go down.
     */
    public function pickOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->orderProducts()->get() as $orderProduct) {
                /** @var OrderProduct $orderProduct */
                $product = $orderProduct->getProduct();
                $qty = $orderProduct->getQty();

                $stock = ProductStock::where('product_id', $product->getId())
                    ->lockForUpdate()
                    ->first();

                if (!$stock) {
                    throw new RuntimeException(
                        "No stock row found for product {$product->getId()}"
                    );
                }

                if ($stock->getPhysicalStock() < $qty) {
                    // This should not happen if purchase order receiving is correct
                    throw new RuntimeException(
                        "Physical stock not sufficient for product {$product->getId()}"
                    );
                }

                $stock->setPhysicalStock($stock->getPhysicalStock() - $qty);
                $stock->setReservedStock($stock->getReservedStock() - $qty);

                // if ($stock->getReservedStock() < 0) {
                //     $stock->setReservedStock(0);
                // }

                $stock->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'quantity'   => -$qty,
                    'type'       => 'sale',
                    'ref_table'  => 'orders',
                    'ref_id'     => $order->id,
                ]);
            }
        });
    }
}

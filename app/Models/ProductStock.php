<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $table = 'product_stock';

    protected $fillable = [
        'product_id',
        'physical_stock',
        'reserved_stock',
        'min_threshold',
        'allow_backorder',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isBelowThreshold(): bool
    {
        return $this->available_stock <= $this->min_threshold;
    }

    public function isBackordered(): bool
    {
        return $this->available_stock < 0;
    }

    public function backorderedQuantity(): int
    {
        // part of the reservation that is not covered by physical stock
        return max(0, $this->reserved_stock - $this->physical_stock);
    }

    /**
     * Check if product is in stock
     * If backorders are not allowed, available stock must be greater than zero
     * Otherwise, product is considered in stock. Backorders can always be placed.
     */
    public function isInStock(): bool
    {
        $allowBackorder = $this->getAllowBackorder() ?? true;

        if (!$allowBackorder && $this->getAvailableStock() <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Check if the requested quantity can be ordered
     * If backorders are not allowed, available stock must be sufficient
     * Otherwise, any quantity can be ordered.
     */
    public function canOrderForQuantity(int $qty): bool
    {
        $allowBackorder = $this->getAllowBackorder() ?? true;

        if (!$allowBackorder && $this->getAvailableStock() < $qty) {
            return false;
        }

        return true;
    }

    public function getProductId(): int
    {
        return $this->product_id;
    }

    public function setProductId(int $productId): void
    {
        $this->product_id = $productId;
    }

    public function getPhysicalStock(): int
    {
        return $this->physical_stock;
    }

    public function setPhysicalStock(int $physicalStock): void
    {
        $this->physical_stock = $physicalStock;
    }

    public function getReservedStock(): int
    {
        return $this->reserved_stock;
    }

    public function setReservedStock(int $reservedStock): void
    {
        $this->reserved_stock = $reservedStock;
    }

    public function getMinThreshold(): int
    {
        return $this->min_threshold;
    }

    public function setMinThreshold(int $minThreshold): void
    {
        $this->min_threshold = $minThreshold;
    }

    public function getAllowBackorder(): bool
    {
        return $this->allow_backorder;
    }

    public function setAllowBackorder(bool $allowBackorder): void
    {
        $this->allow_backorder = $allowBackorder;
    }

    public function getAvailableStock(): int
    {
        return $this->available_stock;
    }

    public function calculateAvailableStock(): int
    {
        return $this->physical_stock - $this->reserved_stock;
    }
}

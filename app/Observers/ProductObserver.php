<?php

namespace App\Observers;

use App\Enums\PriceChangeMethod;
use App\Enums\PriceType;
use App\Models\PriceChangeLog;
use App\Models\Product;
use App\Support\Pricing\ProductPricingCalculator;

class ProductObserver
{
    /**
     * @var array<int, array<int, array{type: PriceType, from: ?float, to: ?float, action: string, method: PriceChangeMethod, comment: ?string}>>
     */
    private static array $pendingLogs = [];

    public function updating(Product $product): void
    {
        $pending = [];
        $actionContext = is_array($product->price_change_action_context ?? null)
            ? $product->price_change_action_context
            : [];
        $method = PriceChangeMethod::tryFrom((string) ($actionContext['_method'] ?? '')) ?? PriceChangeMethod::Manual;
        $comment = isset($actionContext['_comment']) && trim((string) $actionContext['_comment']) !== ''
            ? trim((string) $actionContext['_comment'])
            : null;

        $priceFields = [
            PriceType::CompanyPurchasePrice->value => PriceType::CompanyPurchasePrice,
            PriceType::CompanySalesPrice->value => PriceType::CompanySalesPrice,
            PriceType::CompanyMargin->value => PriceType::CompanyMargin,
            PriceType::CompanyMarkup->value => PriceType::CompanyMarkup,
        ];

        foreach ($priceFields as $field => $type) {
            if (! $product->isDirty($field)) {
                continue;
            }

            if ($field === PriceType::CompanySalesPrice->value && $this->shouldSkipDerivedSalesPriceLog($product)) {
                continue;
            }

            $from = $this->toFloatOrNull($product->getOriginal($field));
            $to = $this->toFloatOrNull($product->getAttribute($field));

            if ($from === $to) {
                continue;
            }

            $pending[] = [
                'type' => $type,
                'from' => $from,
                'to' => $to,
                'action' => PriceChangeLog::buildActionLabel($from, $to, $actionContext[$field] ?? null),
                'method' => $method,
                'comment' => $comment,
            ];
        }

        self::$pendingLogs[$product->getKey()] = $pending;
    }

    public function updated(Product $product): void
    {
        $pending = self::$pendingLogs[$product->getKey()] ?? [];
        unset(self::$pendingLogs[$product->getKey()]);

        if ($pending === []) {
            return;
        }

        $createdAt = now();
        $userId = auth()->id();

        foreach ($pending as $entry) {
            PriceChangeLog::create([
                'type' => $entry['type']->value,
                'product_id' => $product->getKey(),
                'value_from' => $entry['from'],
                'value_to' => $entry['to'],
                'action' => $entry['action'],
                'method' => $entry['method']->value,
                'user_id' => $userId,
                'comment' => $entry['comment'],
                'created_at' => $createdAt,
            ]);
        }

        $product->price_change_action_context = null;
    }

    private function shouldSkipDerivedSalesPriceLog(Product $product): bool
    {
        $actionContext = is_array($product->price_change_action_context ?? null)
            ? $product->price_change_action_context
            : [];

        if (array_key_exists(PriceType::CompanySalesPrice->value, $actionContext)) {
            return false;
        }

        if (! $product->isDirty(PriceType::CompanySalesPrice->value)) {
            return false;
        }

        $purchaseDirty = $product->isDirty(PriceType::CompanyPurchasePrice->value);
        $marginDirty = $product->isDirty(PriceType::CompanyMargin->value);
        $markupDirty = $product->isDirty(PriceType::CompanyMarkup->value);

        if (! $purchaseDirty && ! $marginDirty && ! $markupDirty) {
            return false;
        }

        $purchase = (float) ($product->getAttribute(PriceType::CompanyPurchasePrice->value) ?? 0);
        $margin = (float) ($product->getAttribute(PriceType::CompanyMargin->value) ?? 0);
        $markup = (float) ($product->getAttribute(PriceType::CompanyMarkup->value) ?? 0);
        $sales = (float) ($product->getAttribute(PriceType::CompanySalesPrice->value) ?? 0);
        $expectedFromMargin = ProductPricingCalculator::recalculateSalesFromPurchaseAndMargin($purchase, $margin);
        $expectedFromMarkup = ProductPricingCalculator::recalculateSalesFromPurchaseAndMarkup($purchase, $markup);

        if ($marginDirty && abs($sales - $expectedFromMargin) < 0.0001) {
            return true;
        }

        if (($markupDirty || $purchaseDirty) && abs($sales - $expectedFromMarkup) < 0.0001) {
            return true;
        }

        return false;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}


<?php

namespace App\Services\Exact\Products;

use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Models\ExactArticleGroup;
use App\Models\ExactVATCode;
use App\Models\Product;
use App\Services\ExactOnlineService;
use App\Support\Pricing\ProductPricingCalculator;
use Throwable;

class ExactProductImportService
{
    public function __construct(
        private ExactProducts $exactProducts,
        private ExactOnlineService $exactOnline,
    ) {}

    /**
     * Import all Items from Exact into local products (match only on exact_id).
     *
     * @param  callable(int): void|null  $onProgressStart  Invoked once with total row count after items are fetched.
     * @param  callable(): void|null  $onProgressStep  Invoked after each row is handled (including skipped or failed rows).
     * @return array{processed: int, created: int, updated: int, failed: int}
     */
    public function import(
        int $concurrency = 10,
        ?callable $onProgressStart = null,
        ?callable $onProgressStep = null,
        ?array $onlyProductIds = null,
    ): array {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        if ($onlyProductIds !== null && $onlyProductIds !== []) {
            $allowedExactIds = Product::query()
                ->whereIn('id', $onlyProductIds)
                ->whereNotNull('exact_id')
                ->pluck('exact_id')
                ->map(fn (mixed $value): string => $this->normalizeExactGuid($value))
                ->filter(fn (string $value): bool => $value !== '')
                ->values()
                ->all();

            if ($allowedExactIds === []) {
                $items = [];
            } else {
                $items = [];
                foreach (array_chunk($allowedExactIds, 20) as $idChunk) {
                    $parts = array_map(
                        fn (string $guid): string => "ID eq guid'{$guid}'",
                        $idChunk
                    );
                    $filter = '(' . implode(' or ', $parts) . ')';

                    $batch = $this->exactProducts->fetchAll(
                        $filter,
                        ExactProducts::MAX_PAGE_SIZE,
                        ExactProducts::ITEM_IMPORT_SELECT
                    );

                    if ($batch === []) {
                        $batch = $this->exactProducts->fetchAll(
                            $filter,
                            ExactProducts::MAX_PAGE_SIZE,
                            null
                        );
                    }

                    $items = array_merge($items, $batch);
                }
            }
        } else {
            $items = $this->exactProducts->fetchAll(
                null,
                ExactProducts::MAX_PAGE_SIZE,
                ExactProducts::ITEM_IMPORT_SELECT
            );

            if ($items === []) {
                $items = $this->exactProducts->fetchAll(
                    null,
                    ExactProducts::MAX_PAGE_SIZE,
                    null
                );
            }
        }

        $items = $this->sortExactItemsByOldestSyncedFirst($items);

        $onProgressStart?->__invoke(count($items));

        $concurrency = max(1, $concurrency);

        foreach (array_chunk($items, $concurrency) as $chunk) {
            foreach ($chunk as $item) {
                if (! is_array($item)) {
                    $onProgressStep?->__invoke();

                    continue;
                }

                $rawId = $item['ID'] ?? null;
                $id = ($rawId !== null && $rawId !== '') ? trim((string) $rawId) : '';
                if ($id === '') {
                    $onProgressStep?->__invoke();

                    continue;
                }

                $item['ID'] = $id;

                try {
                    $result = $this->upsertProductFromExactItem($item);
                    $stats['processed']++;
                    if ($result === 'created') {
                        $stats['created']++;
                    } elseif ($result === 'updated') {
                        $stats['updated']++;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $this->exactOnline->log(
                        'ExactProductImport: item '.$item['ID'].' '.$e->getMessage(),
                        'error'
                    );
                }

                $onProgressStep?->__invoke();
            }
        }

        return $stats;
    }

    /**
     * Order Items so locally stale rows (oldest exact_synced_at) are processed first; unknown exact_id first.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortExactItemsByOldestSyncedFirst(array $items): array
    {
        $syncedAtByLowerExactId = [];
        foreach (Product::query()->whereNotNull('exact_id')->pluck('exact_synced_at', 'exact_id') as $exactId => $syncedAt) {
            $key = strtolower(trim((string) $exactId));
            if ($key === '') {
                continue;
            }
            $syncedAtByLowerExactId[$key] = $syncedAt;
        }

        usort($items, function (array $a, array $b) use ($syncedAtByLowerExactId): int {
            $tsA = $this->exactItemStaleSortTimestamp($a, $syncedAtByLowerExactId);
            $tsB = $this->exactItemStaleSortTimestamp($b, $syncedAtByLowerExactId);

            return $tsA <=> $tsB;
        });

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $syncedAtByLowerExactId
     */
    private function exactItemStaleSortTimestamp(array $item, array $syncedAtByLowerExactId): int
    {
        $rawId = $item['ID'] ?? null;
        $id = ($rawId !== null && $rawId !== '') ? strtolower(trim((string) $rawId)) : '';
        if ($id === '') {
            return PHP_INT_MAX;
        }

        if (! array_key_exists($id, $syncedAtByLowerExactId)) {
            return 0;
        }

        $synced = $syncedAtByLowerExactId[$id];
        if ($synced === null) {
            return 0;
        }

        return $synced->getTimestamp();
    }

    private function normalizeExactGuid(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        return str_replace(['{', '}'], '', $normalized);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function upsertProductFromExactItem(array $item): string
    {
        $exactId = trim((string) ($item['ID'] ?? ''));
        if ($exactId === '') {
            throw new \InvalidArgumentException('Exact item has no ID');
        }

        $product = Product::query()
            ->whereRaw('LOWER(exact_id) = ?', [strtolower($exactId)])
            ->first();
        $wasNew = $product === null;

        if ($product === null) {
            $product = new Product;
            $product->exact_id = $exactId;
        }

        $product->uid = (string) ($item['Code'] ?? '');
        $product->name = trim((string) ($item['Description'] ?? ''));
        $product->type = $this->inferProductTypeFromName($product->name);
        $product->comment = $this->nullableString($item['ExtraDescription'] ?? null);

        $product->company_sales_price = $this->decimalOrZero($item['StandardSalesPrice'] ?? null);
        $product->company_purchase_price = $this->decimalOrZero($item['CostPriceStandard'] ?? null);
        $product->company_margin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales(
            (float) $product->company_purchase_price,
            (float) $product->company_sales_price,
        );
        $product->company_markup = ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales(
            (float) $product->company_purchase_price,
            (float) $product->company_sales_price,
        );

        $unit = ProductUnit::tryFromExact(
            isset($item['UnitDescription']) ? (string) $item['UnitDescription'] : null,
            isset($item['Unit']) ? (string) $item['Unit'] : null
        );
        $product->unit = $unit;

        $itemGroupGuid = $item['ItemGroup'] ?? null;
        if (is_string($itemGroupGuid) && $itemGroupGuid !== '') {
            $group = ExactArticleGroup::query()->where('guid', $itemGroupGuid)->first();
            $product->exact_article_group_id = $group?->id;
            if ($group === null) {
                $this->exactOnline->log(
                    'ExactProductImport: ExactArticleGroup not found for ItemGroup '.$itemGroupGuid.' (product exact_id '.$exactId.')',
                    'warning'
                );
            }
        } else {
            $product->exact_article_group_id = null;
        }

        $salesVatValue = $item['SalesVatCode'] ?? $item['SalesVATCode'] ?? $item['salesVatCode'] ?? null;
        $salesVatValue = is_string($salesVatValue) ? trim($salesVatValue) : null;
        if ($salesVatValue !== null && $salesVatValue !== '') {
            $salesVatId = $this->resolveExactVatCodeId($salesVatValue);
            $product->exact_sales_vat_code_id = $salesVatId;
            if ($salesVatId === null) {
                $this->exactOnline->log(
                    'ExactProductImport: ExactVATCode not found for SalesVatCode '.$salesVatValue.' (product exact_id '.$exactId.')',
                    'warning'
                );
            }
        } else {
            $product->exact_sales_vat_code_id = null;
        }

        $purchaseVatGuid = $item['PurchaseVATCode'] ?? $item['PurchaseVatCode'] ?? null;
        $purchaseVatGuid = is_string($purchaseVatGuid) ? trim($purchaseVatGuid) : null;
        if (is_string($purchaseVatGuid) && $purchaseVatGuid !== '') {
            $purchaseVatId = $this->resolveExactVatCodeId($purchaseVatGuid);
            $product->exact_purchase_vat_code_id = $purchaseVatId;
            if ($purchaseVatId === null) {
                $this->exactOnline->log(
                    'ExactProductImport: ExactVATCode not found for PurchaseVATCode '.$purchaseVatGuid.' (product exact_id '.$exactId.')',
                    'warning'
                );
            }
        } else {
            $product->exact_purchase_vat_code_id = null;
        }

        $product->is_stock_enabled = $this->exactTruthy($item['IsStockItem'] ?? null) ? 1 : 0;
        $product->is_fraction_allowed_item = $this->exactTruthy($item['IsFractionAllowedItem'] ?? null);
        $product->is_purchase_item = $this->exactTruthy($item['IsPurchaseItem'] ?? null);
        $product->is_sales_item = $this->exactTruthy($item['IsSalesItem'] ?? null);
        $product->is_on_demand_item = $this->exactTruthy($item['IsOnDemandItem'] ?? null);

        $product->exact_synced_at = now();
        $product->save();

        return $wasNew ? 'created' : 'updated';
    }

    private function resolveExactVatCodeId(string $guidOrCode): ?int
    {
        $trimmed = trim($guidOrCode);
        if ($trimmed === '') {
            return null;
        }

        $normalizedGuid = $this->normalizeExactGuidString($trimmed);
        if ($normalizedGuid !== null) {
            $idByGuid = ExactVATCode::query()->where('guid', $normalizedGuid)->value('id');
            if (is_numeric($idByGuid)) {
                return (int) $idByGuid;
            }
        }

        $idByCode = ExactVATCode::query()->where('code', $trimmed)->value('id');
        if (is_numeric($idByCode)) {
            return (int) $idByCode;
        }

        return null;
    }

    /**
     * Canonical lowercase Exact GUID for comparisons (strips BOM/zero-width, optional OData braces).
     */
    private function normalizeExactGuidString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $stripped = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        if (! is_string($stripped) || $stripped === '') {
            return null;
        }

        $value = trim($stripped);
        $value = str_replace(
            ["\u{2013}", "\u{2014}", "\u{2212}", "\u{00A0}", "\u{202F}"],
            ['-', '-', '-', '', ''],
            $value
        );
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $value = trim(substr($value, 1, -1));
        }

        $parsed = $this->guidFromExactODataReference($value);
        if ($parsed === null) {
            return null;
        }

        return strtolower($parsed);
    }

    private function guidFromExactODataReference(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match("/guid['\"]([0-9a-fA-F\\-]{36})['\"]/i", $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/i', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function exactTruthy(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN)
            || $value === 1
            || $value === '1';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function decimalOrZero(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '0';
    }

    /**
     * Default part; name contains "rolstoel" → frame; "arbeid" → service (rolstoel takes precedence over arbeid).
     */
    private function inferProductTypeFromName(string $name): ProductType
    {
        $lower = mb_strtolower($name, 'UTF-8');
        if (str_contains($lower, 'rolstoel')) {
            return ProductType::Frame;
        }
        if (str_contains($lower, 'arbeid')) {
            return ProductType::Service;
        }

        return ProductType::Part;
    }
}

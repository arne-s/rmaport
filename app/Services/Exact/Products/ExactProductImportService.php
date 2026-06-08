<?php

namespace App\Services\Exact\Products;

use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Models\ExactArticleGroup;
use App\Models\ExactVATCode;
use App\Models\Product;
use App\Models\Supplier;
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

                    // Some Exact environments reject one or more selected fields.
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

            // Some Exact environments reject one or more selected fields and return an empty result.
            // Fall back to default field set so import keeps working.
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
     * Re-fetch SupplierItem data from Exact for every local product with an exact_id (no logistics/Items list fetch).
     *
     * @param  list<int>|null  $onlyProductIds  When set, only these local product IDs are processed.
     * @return array{processed: int, created: int, updated: int, failed: int}
     */
    public function syncSupplierLinksOnly(
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

        $query = Product::query()->whereNotNull('exact_id');
        if ($onlyProductIds !== null && $onlyProductIds !== []) {
            $query->whereIn('id', $onlyProductIds);
        }

        $query->orderByRaw('CASE WHEN supplier_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id');

        $products = $query->get();
        $onProgressStart?->__invoke($products->count());

        $chunkSize = ExactProducts::SUPPLIER_ITEM_BATCH_GUID_COUNT;
        foreach ($products->chunk($chunkSize) as $productChunk) {
            /** @var \Illuminate\Support\Collection<int, Product> $productChunk */
            $uniqueGuids = [];
            foreach ($productChunk as $product) {
                $g = strtolower(trim((string) $product->exact_id));
                if ($g !== '') {
                    $uniqueGuids[] = $g;
                }
            }
            $uniqueGuids = array_values(array_unique($uniqueGuids));

            $rowsByItemGuid = [];
            if ($uniqueGuids !== []) {
                foreach ($this->exactProducts->fetchSupplierItemsPageForItemGuids($uniqueGuids) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $itemKey = $this->supplierItemRowItemGuidLower($row);
                    if ($itemKey !== null) {
                        $rowsByItemGuid[$itemKey][] = $row;
                    }
                }
            }

            foreach ($productChunk as $product) {
                try {
                    $g = strtolower(trim((string) $product->exact_id));
                    if ($g === '') {
                        $stats['processed']++;
                        $onProgressStep?->__invoke();

                        continue;
                    }
                    $rows = $rowsByItemGuid[$g] ?? [];
                    if ($rows === []) {
                        $rows = $this->exactProducts->fetchAllSupplierItemsForItem($g);
                    }
                    $this->applySupplierItemsFromRows($product, $rows);
                    $stats['processed']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $this->exactOnline->log(
                        'ExactProductImport supplier-links: product '.$product->id.' '.$e->getMessage(),
                        'error'
                    );
                }

                $onProgressStep?->__invoke();
            }
        }

        return $stats;
    }

    /**
     * Refresh supplier link fields for one product using Exact SupplierItem rows.
     */
    public function syncSupplierLinksForProduct(Product $product): void
    {
        $exactId = $product->exact_id;
        if (! is_string($exactId) || trim($exactId) === '') {
            throw new \InvalidArgumentException('Product has no exact_id');
        }

        $this->applySupplierItems($product, trim($exactId));
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

        $supplierRows = $this->exactProducts->fetchAllSupplierItemsForItem($exactId);
        $preferredSupplierRow = $this->selectPreferredSupplierItemRow($supplierRows);
        // Purchase price: logistics/SupplierItem.PurchasePrice (active supplier purchase price), not Items.CostPriceStandard (standard cost).
        $purchaseFromSupplier = $preferredSupplierRow !== null
            ? $this->supplierItemPurchasePrice($preferredSupplierRow)
            : null;
        $product->company_purchase_price = $this->decimalOrZero($purchaseFromSupplier ?? 0);
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

        $this->applySupplierItemsFromRows($product, $supplierRows);

        return $wasNew ? 'created' : 'updated';
    }

    private function applySupplierItems(Product $product, string $itemGuid): void
    {
        $rows = $this->exactProducts->fetchAllSupplierItemsForItem($itemGuid);
        $this->applySupplierItemsFromRows($product, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function applySupplierItemsFromRows(Product $product, array $rows): void
    {
        $row = $this->selectPreferredSupplierItemRow($rows);

        if ($row === null) {
            return;
        }

        $supplier = $this->findSupplierForSupplierItemRow($row);
        $product->supplier_id = $supplier?->id;
        if ($supplier === null) {
            $guid = $this->supplierItemRowSupplierGuid($row);
            $code = $this->nullableString($row['SupplierCode'] ?? $row['supplierCode'] ?? null);
            if ($guid !== null || $code !== null) {
                $this->exactOnline->log(
                    'ExactProductImport: Supplier not found (GUID '.($guid ?? 'n/a').', account code '.($code ?? 'n/a').') for product id '.$product->id,
                    'warning'
                );
            }
        }

        $product->supplier_product_uid = $this->supplierItemCodeForUid($row);
        $product->supplier_product_name = $this->nullableString(
            $row['ItemDescription'] ?? $row['itemDescription'] ?? $row['Description'] ?? $row['description'] ?? null
        );

        $supplierItemId = $row['ID'] ?? $row['Id'] ?? $row['id'] ?? null;
        $product->exact_supplier_item_id = is_string($supplierItemId) ? $supplierItemId : null;

        $purchaseVatCode = $this->nullableString(
            $row['PurchaseVATCode'] ?? $row['PurchaseVatCode'] ?? $row['purchaseVatCode'] ?? null
        );
        if ($purchaseVatCode !== null) {
            $purchaseVatId = $this->resolveExactVatCodeId($purchaseVatCode);
            if ($purchaseVatId !== null) {
                $product->exact_purchase_vat_code_id = $purchaseVatId;
            } else {
                $this->exactOnline->log(
                    'ExactProductImport: ExactVATCode not found for SupplierItem.PurchaseVATCode '.$purchaseVatCode.' (product id '.$product->id.')',
                    'warning'
                );
            }
        }

        $purchaseFromSupplier = $this->supplierItemPurchasePrice($row);
        if ($purchaseFromSupplier !== null) {
            $product->company_purchase_price = $this->decimalOrZero($purchaseFromSupplier);
            $product->company_margin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales(
                (float) $product->company_purchase_price,
                (float) $product->company_sales_price,
            );
            $product->company_markup = ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales(
                (float) $product->company_purchase_price,
                (float) $product->company_sales_price,
            );
        }

        $product->save();
    }

    /**
     * Purchase price from the preferred SupplierItem row (logistics/SupplierItem.PurchasePrice).
     *
     * @param  array<string, mixed>  $row
     */
    private function supplierItemPurchasePrice(array $row): ?float
    {
        foreach (['PurchasePrice', 'Price', 'purchasePrice', 'price'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $v = $row[$key];
            if ($v === null || $v === '' || ! is_numeric($v)) {
                continue;
            }

            return (float) $v;
        }

        return null;
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
     * Item GUID from a SupplierItem payload (lowercase) for grouping batched API responses.
     *
     * @param  array<string, mixed>  $row
     */
    private function supplierItemRowItemGuidLower(array $row): ?string
    {
        foreach (['Item', 'item'] as $key) {
            $v = $row[$key] ?? null;
            if (is_string($v) || is_int($v) || is_float($v)) {
                $t = strtolower(trim((string) $v));

                return $t !== '' ? $t : null;
            }
        }

        return null;
    }

    /**
     * Match suppliers.exact_id allowing braces/BOM/spacing differences vs Exact API GUID strings.
     */
    private function findSupplierByNormalizedExactId(string $normalizedLowerGuid): ?Supplier
    {
        return Supplier::query()
            ->whereRaw(
                'LOWER(TRIM(REPLACE(REPLACE(COALESCE(exact_id, ?), ?, ?), ?, ?))) = ?',
                ['', '{', '', '}', '', $normalizedLowerGuid]
            )
            ->first();
    }

    /**
     * Resolve local Supplier from a SupplierItem row: Exact account GUID first, then creditor/account code (SupplierCode).
     *
     * @param  array<string, mixed>  $row
     */
    private function findSupplierForSupplierItemRow(array $row): ?Supplier
    {
        $guid = $this->supplierItemRowSupplierGuid($row);
        if ($guid !== null && $guid !== '') {
            $byGuid = $this->findSupplierByNormalizedExactId($guid);
            if ($byGuid !== null) {
                return $byGuid;
            }
        }

        return $this->findSupplierBySupplierItemAccountCode($row);
    }

    /**
     * Match SupplierItem.SupplierCode to suppliers.exact_code / suppliers.reference (same normalization as supplier import).
     *
     * @param  array<string, mixed>  $row
     */
    private function findSupplierBySupplierItemAccountCode(array $row): ?Supplier
    {
        $compact = $this->compactExactAccountCodeFromSupplierItem($row['SupplierCode'] ?? $row['supplierCode'] ?? null);
        if ($compact === null) {
            return null;
        }

        $driver = Supplier::query()->getConnection()->getDriverName();

        return Supplier::query()
            ->where(function ($q) use ($compact, $driver): void {
                $q->where('exact_code', $compact)
                    ->orWhere('reference', $compact);

                if ($driver === 'mysql') {
                    $q->orWhereRaw(
                        "REGEXP_REPLACE(TRIM(COALESCE(exact_code, '')), '[[:space:]]+', '') = ?",
                        [$compact]
                    )->orWhereRaw(
                        "REGEXP_REPLACE(TRIM(COALESCE(reference, '')), '[[:space:]]+', '') = ?",
                        [$compact]
                    );
                }
            })
            ->orderByRaw('CASE WHEN exact_id IS NULL OR exact_id = ? THEN 1 ELSE 0 END', [''])
            ->orderBy('id')
            ->first();
    }

    /**
     * Strip all Unicode whitespace (incl. NBSP) from Exact SupplierItem account code; Exact often pads with spaces.
     */
    private function compactExactAccountCodeFromSupplierItem(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        $compact = preg_replace('/\s+/u', '', (string) $raw);
        if (! is_string($compact) || $compact === '') {
            return null;
        }

        return $compact;
    }

    /**
     * Pick the SupplierItem row to drive supplier_id.
     *
     * Order: (1) exists in local suppliers + MainSupplier, (2) exists + not main, (3) unknown + main,
     * (4) rest. This prefers a resolvable supplier over a "main" row whose account is not imported yet,
     * and avoids picking the wrong row when multiple SupplierItems exist.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function selectPreferredSupplierItemRow(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $packed = [];
        foreach ($rows as $r) {
            $packed[] = [
                'row' => $r,
                'resolvable' => $this->supplierItemRowIsResolvableLocally($r),
            ];
        }

        usort($packed, function (array $a, array $b): int {
            $tier = function (array $pack): int {
                $known = $pack['resolvable'];
                $main = $this->supplierItemMainSupplierRank($pack['row']) === 1;
                if ($known && $main) {
                    return 0;
                }
                if ($known && ! $main) {
                    return 1;
                }
                if (! $known && $main) {
                    return 2;
                }

                return 3;
            };

            $ta = $tier($a);
            $tb = $tier($b);
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }

            $ga = $this->supplierItemRowSupplierGuid($a['row']) ?? '';
            $gb = $this->supplierItemRowSupplierGuid($b['row']) ?? '';

            return strcmp($ga, $gb);
        });

        return $packed[0]['row'];
    }

    /**
     * True when this SupplierItem can be linked to a local supplier by GUID or by Exact account code.
     *
     * @param  array<string, mixed>  $row
     */
    private function supplierItemRowIsResolvableLocally(array $row): bool
    {
        $guid = $this->supplierItemRowSupplierGuid($row);
        if ($guid !== null && $guid !== '' && $this->findSupplierByNormalizedExactId($guid) !== null) {
            return true;
        }

        return $this->findSupplierBySupplierItemAccountCode($row) !== null;
    }

    /**
     * Supplier account GUID from a logistics/SupplierItem payload (Exact shapes differ per endpoint/version).
     */
    private function supplierItemRowSupplierGuid(array $row): ?string
    {
        foreach (['Supplier', 'supplier'] as $key) {
            $v = $row[$key] ?? null;
            if (is_string($v) || is_int($v) || is_float($v)) {
                $n = $this->normalizeExactGuidString(trim((string) $v));
                if ($n !== null) {
                    return $n;
                }
            }
            if (is_array($v)) {
                foreach (['ID', 'Id', 'id'] as $idKey) {
                    $id = $v[$idKey] ?? null;
                    if (is_string($id) && trim($id) !== '') {
                        $n = $this->normalizeExactGuidString(trim($id));
                        if ($n !== null) {
                            return $n;
                        }
                    }
                }
                $deferredUri = $v['__deferred']['uri'] ?? $v['uri'] ?? null;
                if (is_string($deferredUri)) {
                    $parsed = $this->guidFromExactODataReference($deferredUri);
                    if ($parsed !== null) {
                        return $this->normalizeExactGuidString($parsed);
                    }
                }
            }
        }

        foreach (['Supplier@odata.bind', 'supplier@odata.bind'] as $key) {
            $v = $row[$key] ?? null;
            if (is_string($v)) {
                $parsed = $this->guidFromExactODataReference($v);
                if ($parsed !== null) {
                    return $this->normalizeExactGuidString($parsed);
                }
            }
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
        // Typographic dashes / NBSP break the strict UUID pattern; Exact or exports may use them.
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

    private function supplierItemMainSupplierRank(array $row): int
    {
        return $this->isSupplierItemMainSupplierFlag($row['MainSupplier'] ?? $row['mainSupplier'] ?? null) ? 1 : 0;
    }

    private function isSupplierItemMainSupplierFlag(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        if (is_string($value) && strcasecmp(trim($value), 'true') === 0) {
            return true;
        }

        return false;
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
     * Exact UI "Supplier item code" maps to REST property SupplierItemCode on logistics/SupplierItem (fallback ItemCode, Code).
     *
     * @param  array<string, mixed>  $row
     */
    private function supplierItemCodeForUid(array $row): ?string
    {
        foreach (['SupplierItemCode', 'supplierItemCode', 'ItemCode', 'itemCode', 'Code', 'code'] as $key) {
            $v = $this->nullableString($row[$key] ?? null);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
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

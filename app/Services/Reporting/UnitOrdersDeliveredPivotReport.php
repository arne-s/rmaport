<?php

namespace App\Services\Reporting;

use App\Enums\ProductType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

class UnitOrdersDeliveredPivotReport
{
    /**
     * Chair type on products (dedicated column; synced from legacy JSON where applicable).
     */
    public static function chairTypeExpression(string $productsTable = 'products'): string
    {
        return "NULLIF(TRIM({$productsTable}.chair_type), '')";
    }

    /**
     * Column for GROUP BY / ONLY_FULL_GROUP_BY (must not use wrapped expressions).
     */
    public static function chairTypeGroupByColumn(string $productsTable = 'products'): string
    {
        return "{$productsTable}.chair_type";
    }

    /**
     * Frame order lines with delivery and chair type (no calendar-year filter).
     */
    public static function baseQueryWithoutYear(): Builder
    {
        $chair = self::chairTypeExpression('products');

        return DB::table('order_products')
            ->join('products', 'order_products.product_id', '=', 'products.id')
            ->leftJoin('suppliers', 'order_products.supplier_id', '=', 'suppliers.id')
            ->where('order_products.type', ProductType::Frame->value)
            ->whereNotNull('order_products.delivered_at')
            ->whereRaw("{$chair} IS NOT NULL");
    }

    /**
     * @param  int|string  $year  Calendar year or the literal 'all'
     */
    public static function baseFilteredQuery(int|string $year): Builder
    {
        $q = self::baseQueryWithoutYear();
        if ($year !== 'all' && $year !== '' && $year !== null) {
            $q->whereYear('order_products.delivered_at', (int) $year);
        }

        return $q;
    }

    /**
     * @return array{min: int, max: int}
     */
    public static function deliveredYearBounds(): array
    {
        $row = self::baseQueryWithoutYear()
            ->selectRaw('MIN(YEAR(order_products.delivered_at)) as min_y, MAX(YEAR(order_products.delivered_at)) as max_y')
            ->first();

        $minY = (int) ($row->min_y ?? now()->year);
        $maxY = (int) ($row->max_y ?? now()->year);
        if ($minY > $maxY) {
            $y = (int) now()->year;

            return ['min' => $y, 'max' => $y];
        }

        return ['min' => $minY, 'max' => $maxY];
    }

    /**
     * Radio options: Alle jaren + one entry per year that appears in the data (descending).
     *
     * @return array<string, string>
     */
    public static function yearRadioOptions(): array
    {
        $bounds = self::deliveredYearBounds();

        return collect(range($bounds['max'], $bounds['min']))
            ->mapWithKeys(fn (int $year): array => [(string) $year => (string) $year])
            ->prepend('Alle jaren', 'all')
            ->all();
    }

    public static function defaultYearSelection(): string
    {
        return (string) self::deliveredYearBounds()['max'];
    }

    /**
     * @param  int|string  $year  Calendar year or 'all'
     * @return array<string, float>
     */
    public static function grandTotals(int|string $year): array
    {
        $q = self::baseFilteredQuery($year);
        $row = $q
            ->selectRaw(
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 1 THEN order_products.qty ELSE 0 END) as m1, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 2 THEN order_products.qty ELSE 0 END) as m2, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 3 THEN order_products.qty ELSE 0 END) as m3, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 4 THEN order_products.qty ELSE 0 END) as m4, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 5 THEN order_products.qty ELSE 0 END) as m5, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 6 THEN order_products.qty ELSE 0 END) as m6, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 7 THEN order_products.qty ELSE 0 END) as m7, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 8 THEN order_products.qty ELSE 0 END) as m8, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 9 THEN order_products.qty ELSE 0 END) as m9, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 10 THEN order_products.qty ELSE 0 END) as m10, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 11 THEN order_products.qty ELSE 0 END) as m11, ' .
                'SUM(CASE WHEN MONTH(order_products.delivered_at) = 12 THEN order_products.qty ELSE 0 END) as m12, ' .
                'SUM(order_products.qty) as total_all'
            )
            ->first();

        $out = [];
        foreach (range(1, 12) as $m) {
            $key = 'm' . $m;
            $out[$key] = (float) ($row->{$key} ?? 0);
        }
        $out['total_all'] = (float) ($row->total_all ?? 0);

        return $out;
    }

    /**
     * Difference vs the previous calendar year per month and total.
     * Missing data for the previous year is treated as zero.
     *
     * @return array<string, int>|null null when the report is not scoped to a single year
     */
    public static function grandTotalsYearOverYearDiff(int|string $year): ?array
    {
        if ($year === 'all') {
            return null;
        }

        $y = (int) $year;
        $curr = self::grandTotals($y);
        $prev = self::grandTotals($y - 1);

        $out = [];
        foreach (range(1, 12) as $m) {
            $key = 'm' . $m;
            $out[$key] = (int) round($curr[$key] - $prev[$key]);
        }
        $out['total_all'] = (int) round($curr['total_all'] - $prev['total_all']);

        return $out;
    }

    /**
     * @param  int|string  $year  Calendar year or 'all'
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginateGroupedRows(
        int|string $year,
        int $page,
        int $perPage,
        string $pageName = 'page',
        ?string $sortColumn = null,
        ?string $sortDirection = null,
    ): LengthAwarePaginator {
        $base = self::baseFilteredQuery($year);

        $groupCountSub = (clone $base)
            ->select(DB::raw('1'))
            ->groupBy(
                DB::raw('COALESCE(`order_products`.`supplier_id`, 0)'),
                self::chairTypeGroupByColumn('products'),
            );

        $total = (int) DB::query()->fromSub($groupCountSub, 'grouped_rows')->count();

        $offset = max(0, ($page - 1) * $perPage);

        $dir = strtolower((string) ($sortDirection ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $primary = $sortColumn === 'chair_type' ? 'chair_type' : 'supplier_name';

        $rows = $base
            ->selectRaw('MAX(COALESCE(suppliers.name, ?)) as supplier_name', ['—'])
            ->selectRaw('MAX(TRIM(products.chair_type)) as chair_type')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 1 THEN order_products.qty ELSE 0 END) as m1')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 2 THEN order_products.qty ELSE 0 END) as m2')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 3 THEN order_products.qty ELSE 0 END) as m3')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 4 THEN order_products.qty ELSE 0 END) as m4')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 5 THEN order_products.qty ELSE 0 END) as m5')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 6 THEN order_products.qty ELSE 0 END) as m6')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 7 THEN order_products.qty ELSE 0 END) as m7')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 8 THEN order_products.qty ELSE 0 END) as m8')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 9 THEN order_products.qty ELSE 0 END) as m9')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 10 THEN order_products.qty ELSE 0 END) as m10')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 11 THEN order_products.qty ELSE 0 END) as m11')
            ->selectRaw('SUM(CASE WHEN MONTH(order_products.delivered_at) = 12 THEN order_products.qty ELSE 0 END) as m12')
            ->selectRaw(
                'SUM(CASE WHEN MONTH(order_products.delivered_at) IN (1,2,3,4,5,6,7,8,9,10,11,12) THEN order_products.qty ELSE 0 END) as row_total'
            )
            ->groupBy(
                DB::raw('COALESCE(`order_products`.`supplier_id`, 0)'),
                self::chairTypeGroupByColumn('products'),
            );

        if ($primary === 'chair_type') {
            $rows->orderBy('chair_type', $dir)->orderBy('supplier_name', 'asc');
        } else {
            $rows->orderBy('supplier_name', $dir)->orderBy('chair_type', 'asc');
        }

        $rows = $rows
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $items = $rows->map(function (object $row, int $i) use ($offset): array {
            $arr = (array) $row;
            $arr['__key'] = 'row-' . ($offset + $i) . '-' . md5(($arr['supplier_name'] ?? '') . '|' . ($arr['chair_type'] ?? ''));

            return $arr;
        })->all();

        return new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }
}

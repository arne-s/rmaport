<?php

namespace App\Filament\Resources\SupplierRevenueResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\SupplierResource;
use App\Filament\Support\PurchaseAuthorization;
use App\Filament\Resources\SupplierRevenueResource;
use App\Filament\Support\ImportExportAuthorization;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListSupplierRevenue extends ListRecords
{
    private const DEFAULT_TIME_UNIT = 'month';

    /**
     * @var array<string, string>
     */
    private const TIME_FILTER_OPTIONS = [
        'week' => 'Per week',
        'month' => 'Per maand',
        'quarter' => 'Per kwartaal',
        'year' => 'Per jaar',
    ];

    /**
     * @var array<int, string>
     */
    private const MONTH_PERIOD_LABELS = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maart',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Augustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'December',
    ];

    protected static string $resource = SupplierRevenueResource::class;

    protected static ?string $breadcrumb = 'Omzet per leverancier';

    protected static ?string $title = 'Omzet per leverancier';

    protected function supplierRevenueTimeUnit(): string
    {
        $state = $this->getTableFilterState('supplier_revenue_time_unit');

        return $state['time_unit'] ?? self::DEFAULT_TIME_UNIT;
    }

    /**
     * @return array{min: int, max: int}
     */
    protected function invoiceSentYearBounds(): array
    {
        $invoiceTypes = [
            OrderType::Invoice->value,
            OrderType::DepositInvoice->value,
        ];

        $row = DB::table('orders')
            ->whereIn('type', $invoiceTypes)
            ->whereNotNull('sent_at')
            ->selectRaw('MIN(YEAR(sent_at)) as min_y, MAX(YEAR(sent_at)) as max_y')
            ->first();

        $minY = (int) ($row->min_y ?? now()->year);
        $maxY = (int) ($row->max_y ?? now()->year);

        if ($minY > $maxY) {
            $y = now()->year;

            return ['min' => $y, 'max' => $y];
        }

        return ['min' => $minY, 'max' => $maxY];
    }

    /**
     * @return array<string, string>
     */
    protected function supplierRevenueYearRadioOptions(): array
    {
        $bounds = $this->invoiceSentYearBounds();

        return collect(range($bounds['max'], $bounds['min']))
            ->mapWithKeys(fn (int $year): array => [(string) $year => (string) $year])
            ->prepend('Alle jaren', 'all')
            ->toArray();
    }

    /**
     * @return array<string, string>
     */
    protected function supplierRevenuePeriodRadioOptions(): array
    {
        $unit = $this->supplierRevenueTimeUnit();

        if ($unit === 'week') {
            $opts = collect(range(1, 53))
                ->mapWithKeys(fn (int $w): array => [(string) $w => 'Week ' . $w])
                ->toArray();

            return ['all' => 'Alle weken'] + $opts;
        }

        if ($unit === 'month') {
            $opts = collect(self::MONTH_PERIOD_LABELS)
                ->mapWithKeys(fn (string $label, int $m): array => [(string) $m => $label])
                ->toArray();

            return ['all' => 'Alle maanden'] + $opts;
        }

        if ($unit === 'quarter') {
            $opts = collect(range(1, 4))
                ->mapWithKeys(fn (int $q): array => [(string) $q => 'Kwartaal ' . $q])
                ->toArray();

            return ['all' => 'Alle kwartalen'] + $opts;
        }

        return ['all' => '—'];
    }

    protected function baseSupplierRevenueQuery(): Builder
    {
        $invoiceTypes = [
            OrderType::Invoice->value,
            OrderType::DepositInvoice->value,
        ];

        $excludedStatuses = [
            OrderGeneralStatus::Initial->value,
            OrderGeneralStatus::Draft->value,
        ];

        return Supplier::query()
            ->select([
                'suppliers.id',
                'suppliers.name',
                DB::raw('COUNT(DISTINCT order_products.product_id) as product_count'),
                DB::raw('SUM(COALESCE(order_products.company_purchase_price_total, 0)) as purchase_total'),
                DB::raw('SUM(COALESCE(order_products.company_sales_price_total, 0)) as sales_total'),
                DB::raw(
                    'SUM(COALESCE(order_products.company_sales_price_total, 0) - COALESCE(order_products.company_purchase_price_total, 0)) as margin_total'
                ),
            ])
            ->join('order_products', function ($join): void {
                $join->on('order_products.supplier_id', '=', 'suppliers.id');
            })
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->whereIn('orders.type', $invoiceTypes)
            ->whereNotNull('orders.sent_at')
            ->whereNotIn('orders.status', $excludedStatuses)
            ->where('orders.is_test', 0)
            ->where(function (Builder $q): void {
                $q->where('orders.is_cancelled', 0)
                    ->orWhereNull('orders.is_cancelled');
            })
            ->whereNotNull('order_products.supplier_id')
            ->whereNotNull('order_products.product_id')
            ->groupBy('suppliers.id', 'suppliers.name')
            ->havingRaw('SUM(order_products.qty) > 0');
    }

    protected function getSupplierRevenueTimeUnitFilter(): Filter
    {
        return Filter::make('supplier_revenue_time_unit')
            ->label('Weergave')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state): string => ! empty($state['time_unit'])
                        ? 'Weergave: ' . (self::TIME_FILTER_OPTIONS[$state['time_unit']] ?? '')
                        : 'Weergave')
                    ->schema([
                        Radio::make('time_unit')
                            ->hiddenLabel()
                            ->options(self::TIME_FILTER_OPTIONS)
                            ->default(self::DEFAULT_TIME_UNIT)
                            ->afterStateUpdatedJs('location.reload();'),
                    ]),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query);
    }

    protected function getSupplierRevenuePeriodFilter(): Filter
    {
        $options = $this->supplierRevenuePeriodRadioOptions();

        return Filter::make('supplier_revenue_period')
            ->label('Periode')
            ->hidden(fn (): bool => $this->supplierRevenueTimeUnit() === 'year')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state): string => ! empty($state['period'])
                        ? 'Periode: ' . ($options[$state['period']] ?? (string) $state['period'])
                        : 'Periode')
                    ->schema([
                        Radio::make('period')
                            ->hiddenLabel()
                            ->options($options)
                            ->default('all'),
                    ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                if ($this->supplierRevenueTimeUnit() === 'year') {
                    return $query;
                }

                $period = $data['period'] ?? 'all';
                if ($period === 'all' || $period === null || $period === '') {
                    return $query;
                }

                return match ($this->supplierRevenueTimeUnit()) {
                    'week' => $query->whereRaw('WEEK(orders.sent_at, 3) = ?', [(int) $period]),
                    'month' => $query->whereMonth('orders.sent_at', (int) $period),
                    'quarter' => $query->whereRaw('QUARTER(orders.sent_at) = ?', [(int) $period]),
                    default => $query,
                };
            });
    }

    protected function getSupplierRevenueYearFilter(): Filter
    {
        $options = $this->supplierRevenueYearRadioOptions();
        $bounds = $this->invoiceSentYearBounds();
        $defaultYear = (string) $bounds['max'];

        return Filter::make('supplier_revenue_year')
            ->label('Jaar')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state): string => ! empty($state['year'])
                        ? 'Jaar: ' . ($options[$state['year']] ?? (string) $state['year'])
                        : 'Jaar')
                    ->schema([
                        Radio::make('year')
                            ->hiddenLabel()
                            ->options($options)
                            ->default($defaultYear),
                    ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $year = $data['year'] ?? null;
                if (! empty($year) && $year !== 'all') {
                    $query->whereYear('orders.sent_at', (int) $year);
                }

                return $query;
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseSupplierRevenueQuery())
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back mt-1',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Leverancier')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Supplier $record): ?string => PurchaseAuthorization::canManage()
                        ? SupplierResource::getUrl('edit', ['record' => $record])
                        : null)
                    ->color(fn (): ?string => PurchaseAuthorization::canManage() ? 'primary' : null),

                TextColumn::make('product_count')
                    ->label('Aantal producten')
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: '.')
                    ->alignStart()
                    ->sortable(),

                TextColumn::make('purchase_total')
                    ->label('Totaal inkoop')
                    ->money('EUR', locale: 'nl')
                    ->alignStart()
                    ->sortable(),

                TextColumn::make('sales_total')
                    ->label('Totaal verkoop')
                    ->money('EUR', locale: 'nl')
                    ->alignStart()
                    ->sortable(),

                TextColumn::make('margin_total')
                    ->label('Totaal marge')
                    ->money('EUR', locale: 'nl')
                    ->alignStart()
                    ->sortable(),
            ])
            ->defaultSort('sales_total', 'desc')
            ->deferFilters(false)
            ->filters([
                $this->getSupplierRevenueTimeUnitFilter(),
                $this->getSupplierRevenuePeriodFilter(),
                $this->getSupplierRevenueYearFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportSupplierRevenueSpreadsheet()),
            ])
            ->extraAttributes([
                'class' => '[&_td]:whitespace-nowrap',
            ]);
    }

    public function exportSupplierRevenueSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'omzet_leveranciers_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        $filepath = storage_path('app/exports/' . $basename);

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 20;

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues([
            'Leverancier',
            'Aantal producten',
            'Totaal inkoop',
            'Totaal verkoop',
            'Totaal marge',
        ]));

        $query = $this->getFilteredTableQuery()->orderByDesc('sales_total');

        foreach ($query->cursor() as $record) {
            if (! $record instanceof Supplier) {
                continue;
            }

            $writer->addRow(Row::fromValues([
                (string) ($record->name ?? ''),
                (int) round((float) ($record->product_count ?? 0)),
                round((float) ($record->purchase_total ?? 0), 2),
                round((float) ($record->sales_total ?? 0), 2),
                round((float) ($record->margin_total ?? 0), 2),
            ]));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}

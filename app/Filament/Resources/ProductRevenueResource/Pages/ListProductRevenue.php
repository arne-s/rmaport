<?php

namespace App\Filament\Resources\ProductRevenueResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductRevenueResource;
use App\Filament\Support\ImportExportAuthorization;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
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

class ListProductRevenue extends ListRecords
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

    protected static string $resource = ProductRevenueResource::class;

    protected static ?string $title = 'Omzet artikelen';

    protected ?string $heading = 'Omzet artikelen | Gefactureerd (excl. BTW)';

    protected bool $showHeading = false;

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.product-revenue.index') => 'Omzet artikelen',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                        'class' => 'reporting-overview-back',
                        'pageTitle' => $this->getHeading(),
                    ]),
                ...$schema->getComponents(),
            ]);
    }

    protected function productRevenueTimeUnit(): string
    {
        $state = $this->getTableFilterState('product_revenue_time_unit');

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
    protected function productRevenueYearRadioOptions(): array
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
    protected function productRevenuePeriodRadioOptions(): array
    {
        $unit = $this->productRevenueTimeUnit();

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

    protected function baseProductRevenueQuery(): Builder
    {
        $invoiceTypes = [
            OrderType::Invoice->value,
            OrderType::DepositInvoice->value,
        ];

        $excludedStatuses = [
            OrderGeneralStatus::Initial->value,
            OrderGeneralStatus::Draft->value,
        ];

        return Product::query()
            ->select([
                'products.id',
                'products.name',
                'products.uid',
                DB::raw('SUM(order_products.qty) as qty_sold'),
                DB::raw('SUM(order_products.company_sales_price_total) as revenue_total'),
            ])
            ->join('order_products', function ($join): void {
                $join->on('order_products.product_id', '=', 'products.id');
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
            ->whereNotNull('order_products.product_id')
            ->groupBy('products.id', 'products.name', 'products.uid')
            ->havingRaw('SUM(order_products.qty) > 0');
    }

    protected function getProductRevenueTimeUnitFilter(): Filter
    {
        return Filter::make('product_revenue_time_unit')
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

    protected function getProductRevenuePeriodFilter(): Filter
    {
        $options = $this->productRevenuePeriodRadioOptions();

        return Filter::make('product_revenue_period')
            ->label('Periode')
            ->hidden(fn (): bool => $this->productRevenueTimeUnit() === 'year')
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
                if ($this->productRevenueTimeUnit() === 'year') {
                    return $query;
                }

                $period = $data['period'] ?? 'all';
                if ($period === 'all' || $period === null || $period === '') {
                    return $query;
                }

                return match ($this->productRevenueTimeUnit()) {
                    'week' => $query->whereRaw('WEEK(orders.sent_at, 3) = ?', [(int) $period]),
                    'month' => $query->whereMonth('orders.sent_at', (int) $period),
                    'quarter' => $query->whereRaw('QUARTER(orders.sent_at) = ?', [(int) $period]),
                    default => $query,
                };
            });
    }

    protected function getProductRevenueYearFilter(): Filter
    {
        $options = $this->productRevenueYearRadioOptions();
        $bounds = $this->invoiceSentYearBounds();
        $defaultYear = (string) $bounds['max'];

        return Filter::make('product_revenue_year')
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
            ->query($this->baseProductRevenueQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Artikelnaam')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Product $record): ?string => ProductResource::editUrlFor($record))
                    ->color(fn (Product $record): ?string => ProductResource::editUrlFor($record) ? 'primary' : null),

                TextColumn::make('uid')
                    ->label('Artikelnummer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('qty_sold')
                    ->label('Aantal verkocht')
                    ->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: '.')
                    ->alignStart()
                    ->sortable(),

                TextColumn::make('revenue_total')
                    ->label('Omzet in periode (excl. BTW)')
                    ->money('EUR', locale: 'nl')
                    ->alignStart()
                    ->sortable(),
            ])
            ->defaultSort('revenue_total', 'desc')
            ->deferFilters(false)
            ->filters([
                $this->getProductRevenueTimeUnitFilter(),
                $this->getProductRevenuePeriodFilter(),
                $this->getProductRevenueYearFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportProductRevenueSpreadsheet()),
            ])
            ->extraAttributes([
                'class' => '[&_td]:whitespace-nowrap',
            ]);
    }

    public function exportProductRevenueSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'omzet_artikelen_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        $filepath = storage_path('app/exports/' . $basename);

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 20;

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues([
            'Artikelnaam',
            'Artikelnummer',
            'Aantal verkocht',
            'Omzet in periode (excl. BTW)',
        ]));

        $query = $this->getFilteredTableQuery()->orderByDesc('revenue_total');

        foreach ($query->cursor() as $record) {
            if (! $record instanceof Product) {
                continue;
            }

            $writer->addRow(Row::fromValues([
                (string) ($record->name ?? ''),
                (string) ($record->uid ?? ''),
                round((float) ($record->qty_sold ?? 0), 2),
                round((float) ($record->revenue_total ?? 0), 2),
            ]));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}

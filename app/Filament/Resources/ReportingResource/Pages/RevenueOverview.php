<?php

namespace App\Filament\Resources\ReportingResource\Pages;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\ReportingResource;
use App\Filament\Support\ImportExportAuthorization;
use Filament\Forms\Components\Radio;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RevenueOverview extends ListRecords
{
    const DEFAULT_TIME_UNIT = 'month';

    const DEFAULT_REVENUE_SOURCE = 'order';

    const REVENUE_SOURCE_OPTIONS = [
        'order' => 'Order',
        'invoice' => 'Facturatie',
    ];

    const DEFAULT_REVENUE_SEGMENT = 'all';

    /**
     * @return array<string, string>
     */
    protected static function revenueSegmentOptions(): array
    {
        return [
            'all' => 'Allen',
            ...CustomerType::visibleLabelsInCustomerTableFilterOrder(),
        ];
    }

    const TIME_FILTER_OPTIONS = [
        'week' => 'Per week',
        'month' => 'Per maand',
        'quarter' => 'Per kwartaal',
        'year' => 'Per jaar',
    ];
    const MONTHS = [
        'january' => 'jan',
        'february' => 'feb',
        'march' => 'maa',
        'april' => 'apr',
        'may' => 'mei',
        'june' => 'jun',
        'july' => 'jul',
        'august' => 'aug',
        'september' => 'sep',
        'october' => 'okt',
        'november' => 'nov',
        'december' => 'dec',
    ];


    protected static string $resource = ReportingResource::class;
    protected static ?string $title = 'Commercieel';
    protected ?string $heading = 'Commercieel overzicht | Order en Omzet (excl. BTW)';
    protected bool $showHeading = false;


    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.reporting.revenue') => 'Commercieel',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getUnit(): string
    {
        $filter = $this->getTableFilterState('time_unit');
        return $filter['time_unit'] ?? self::DEFAULT_TIME_UNIT;
    }

    protected function getRevenueSource(): string
    {
        $filter = $this->getTableFilterState('revenue_source');
        $value = $filter['revenue_source'] ?? self::DEFAULT_REVENUE_SOURCE;

        return array_key_exists($value, self::REVENUE_SOURCE_OPTIONS)
            ? $value
            : self::DEFAULT_REVENUE_SOURCE;
    }

    protected function getRevenueSegment(): string
    {
        $filter = $this->getTableFilterState('revenue_segment');
        $value = $filter['revenue_segment'] ?? self::DEFAULT_REVENUE_SEGMENT;

        return array_key_exists($value, self::revenueSegmentOptions())
            ? $value
            : self::DEFAULT_REVENUE_SEGMENT;
    }

    protected static function billingCustomerDisplayNameSql(): string
    {
        return "COALESCE(
            NULLIF(TRIM(customers.name), ''),
            TRIM(CONCAT(COALESCE(customers.first_name,''), ' ', COALESCE(customers.middle_name,''), ' ', COALESCE(customers.last_name,'')))
        )";
    }

    protected function formatState() {
        return fn ($state) => '€ ' . number_format((float) $state, 2, ',', '.');
    }

    protected function getQuery(): Builder
    {
        return $this->buildQueryForTimeUnit($this->getUnit(), $this->getRevenueSource(), $this->getRevenueSegment());
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

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->columns([
                TextColumn::make('company_name')
                    ->label('Klant (factuur)')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = "%{$search}%";
                        $displayNameSql = self::billingCustomerDisplayNameSql();

                        return $query->where(function (Builder $q) use ($like, $displayNameSql): void {
                            $q->where('customers.name', 'like', $like)
                                ->orWhere('customers.first_name', 'like', $like)
                                ->orWhere('customers.last_name', 'like', $like)
                                ->orWhere('customers.middle_name', 'like', $like)
                                ->orWhereRaw("({$displayNameSql}) LIKE ?", [$like]);
                        });
                    })
                    ->sortable(),

                TextColumn::make('customer_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): ?string => CustomerType::tryFrom((string) $state)?->getLabel())
                    ->sortable(),

                TextColumn::make('orderCount')
                    ->label(fn (): string => $this->getRevenueSource() === 'invoice'
                        ? 'Aantal facturen'
                        : 'Aantal orders')
                    ->alignCenter()
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label('')
                    ),

                ...$this->getPeriodColumns($this->getUnit()),

                TextColumn::make('total')
                    ->label('Totale omzet')
                    ->formatStateUsing($this->formatState())
                    ->alignRight()
                    ->extraHeaderAttributes(['class' => 'summaryColumn'])
                    ->extraCellAttributes(['class' => 'summaryColumn'])
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label('')
                            ->money('EUR')
                    ),
            ])
            ->defaultSort('company_name')
            ->deferFilters(false)
            ->deferLoading()
            ->paginated(false)
            ->filters([
                $this->getTimeUnitFilter(),
                $this->getRevenueSegmentFilter(),
                $this->getRevenueSourceFilter(),
                $this->getYearFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Action::make('export_excel')
                    ->label('Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportRevenueSpreadsheet()),
            ])
            ->toolbarActions([
                BulkAction::make('export')
                    ->label('Exporteren')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn () => $this->exportSelectedToExcel())
                    ->icon('heroicon-o-document-arrow-down'),
            ])
            ->extraAttributes([
                'class' => 'revenue-overview-table',
            ]);
    }

    /**
     * Return TextColumn definitions for the given time unit.
     * Keys must match the aliases produced by buildQueryForTimeUnit.
     */
    protected function getPeriodColumns(string $unit = self::DEFAULT_TIME_UNIT): array
    {
        if ($unit === 'month') {
            return collect(self::MONTHS)
                ->map(function ($label, $key) {
                    return TextColumn::make($key)
                        ->label($label)
                        ->formatStateUsing($this->formatState())
                        ->alignRight()
                        ->sortable()
                        ->summarize(
                            Sum::make()
                                ->label('')
                                ->money('EUR')
                        );
                })
                ->toArray();
        }

        if ($unit === 'quarter') {
            return collect(range(1, 4))
                ->mapWithKeys(function ($q) {
                    $key = 'quarter_' . $q;
                    return [
                        $key => TextColumn::make($key)
                            ->label('Kwartaal ' . $q)
                            ->formatStateUsing($this->formatState())
                            ->alignRight()
                            ->sortable()
                            ->summarize(
                                Sum::make()
                                    ->label('')
                                    ->money('EUR')
                            )
                    ];
                })
                ->toArray();
        }

        if ($unit === 'week') {
            // weeks 1..53
            return collect(range(1, 53))
                ->mapWithKeys(function ($w) {
                    $key = 'week_' . str_pad($w, 2, '0', STR_PAD_LEFT);
                    return [
                        $key => TextColumn::make($key)
                            ->label('Week ' . $w)
                            ->formatStateUsing($this->formatState())
                            ->alignRight()
                            ->sortable()
                            ->summarize(
                                Sum::make()
                                    ->label('')
                                    ->money('EUR')
                            )
                    ];
                })
                ->toArray();
        }

        // year: dynamically include last 5 years (or detected min/max range)
        $yearsRow = DB::table('orders')->selectRaw('MIN(YEAR(sent_at)) as minY, MAX(YEAR(sent_at)) as maxY')->first();
        $minY = (int) ($yearsRow->minY ?? date('Y') - 4);
        $maxY = (int) ($yearsRow->maxY ?? date('Y'));

        $selectedYear = $this->getTableFilterState('year')['year'] ?? null;

        return collect(range($minY, $maxY))
            ->filter(function ($y) use ($selectedYear) {
                return !$selectedYear || $selectedYear === 'all' || $selectedYear == $y;
            })
            ->mapWithKeys(function ($y) {
                $key = 'year_' . $y;
                return [
                    $key => TextColumn::make($key)
                        ->label((string) $y)
                        ->formatStateUsing($this->formatState())
                        ->alignRight()
                        ->sortable()
                        ->summarize(
                            Sum::make()
                                ->label('')
                                ->money('EUR')
                        )
                ];
            })
            ->toArray();
    }

    protected function getTimeUnitFilter(): Filter
    {
        return Filter::make('time_unit')
            ->label('Weergave')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state) =>
                    !empty($state['time_unit'])
                        ? 'Weergave: ' . self::TIME_FILTER_OPTIONS[$state['time_unit']]
                        : 'Weergave'
                    )
                    ->schema([
                        Radio::make('time_unit')
                            ->hiddenLabel()
                            ->options(self::TIME_FILTER_OPTIONS)
                            ->default(self::DEFAULT_TIME_UNIT)
                            // Reload the page to display new columns
                            ->afterStateUpdatedJs('location.reload();'),
                    ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $unit = $data['time_unit'] ?? self::DEFAULT_TIME_UNIT;
                // return a fresh query built for the selected unit (pivoted columns)
                return $this->buildQueryForTimeUnit($unit, $this->getRevenueSource(), $this->getRevenueSegment());
            });
    }

    protected function getRevenueSegmentFilter(): Filter
    {
        return Filter::make('revenue_segment')
            ->label('Groep')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state): string => ! empty($state['revenue_segment'])
                        ? 'Groep: ' . (self::revenueSegmentOptions()[$state['revenue_segment']] ?? 'Allen')
                        : 'Groep')
                    ->schema([
                        Radio::make('revenue_segment')
                            ->hiddenLabel()
                            ->options(self::revenueSegmentOptions())
                            ->default(self::DEFAULT_REVENUE_SEGMENT)
                            ->afterStateUpdatedJs('location.reload();'),
                    ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $segment = $data['revenue_segment'] ?? self::DEFAULT_REVENUE_SEGMENT;

                if (! array_key_exists($segment, self::revenueSegmentOptions())) {
                    $segment = self::DEFAULT_REVENUE_SEGMENT;
                }

                return $this->buildQueryForTimeUnit($this->getUnit(), $this->getRevenueSource(), $segment);
            });
    }

    protected function getRevenueSourceFilter(): Filter
    {
        return Filter::make('revenue_source')
            ->label('Bron')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state): string => ! empty($state['revenue_source'])
                        ? 'Bron: ' . (self::REVENUE_SOURCE_OPTIONS[$state['revenue_source']] ?? 'Order')
                        : 'Bron')
                    ->schema([
                        Radio::make('revenue_source')
                            ->hiddenLabel()
                            ->options(self::REVENUE_SOURCE_OPTIONS)
                            ->default(self::DEFAULT_REVENUE_SOURCE)
                            ->afterStateUpdatedJs('location.reload();'),
                    ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $source = $data['revenue_source'] ?? self::DEFAULT_REVENUE_SOURCE;

                if (! array_key_exists($source, self::REVENUE_SOURCE_OPTIONS)) {
                    $source = self::DEFAULT_REVENUE_SOURCE;
                }

                return $this->buildQueryForTimeUnit($this->getUnit(), $source, $this->getRevenueSegment());
            });
    }

    protected function getYearFilter(): Filter
    {
        $yearsRow = DB::table('orders')->selectRaw('MIN(YEAR(sent_at)) as minY, MAX(YEAR(sent_at)) as maxY')->first();
        $minY = (int) ($yearsRow->minY ?? date('Y') - 4);
        $maxY = (int) ($yearsRow->maxY ?? date('Y'));

        $options = collect(range($maxY, $minY))
            ->mapWithKeys(fn($year) => [$year => (string) $year])
            ->prepend('Alle jaren', 'all')
            ->toArray();

        return Filter::make('year')
            ->label('Jaar')
            ->schema([
                ToggleFilter::make()
                    ->label(fn (array $state) =>
                    !empty($state['year'])
                        ? 'Jaar: ' . $options[$state['year']]
                        : 'Jaar'
                    )
                    ->schema([
                        Radio::make('year')
                            ->hiddenLabel()
                            ->options($options)
                            ->default((string) $maxY)
                            // Reload the page to display new columns
                            ->afterStateUpdatedJs('location.reload();'),
                    ]),
            ])
            ->query(function (Builder $query, array $data): Builder {
                if (!empty($data['year']) && $data['year'] !== 'all') {
                    $query->whereYear('orders.sent_at', $data['year']);
                }
                return $query;
            });
    }

    /**
     * Build a pivoted query that selects company or customer info and SUM(...) columns per period.
     */
    protected function buildQueryForTimeUnit(
        string $unit,
        string $revenueSource = self::DEFAULT_REVENUE_SOURCE,
        string $segment = self::DEFAULT_REVENUE_SEGMENT,
    ): Builder {
        if (! array_key_exists($revenueSource, self::REVENUE_SOURCE_OPTIONS)) {
            $revenueSource = self::DEFAULT_REVENUE_SOURCE;
        }

        if (! array_key_exists($segment, self::revenueSegmentOptions())) {
            $segment = self::DEFAULT_REVENUE_SEGMENT;
        }

        $customerDisplayNameSql = self::billingCustomerDisplayNameSql();

        $selects = [
            'customers.id as id',
            'customers.type as customer_type',
            DB::raw("({$customerDisplayNameSql}) as company_name"),
        ];
        $excludedOrderStatuses = implode(',', array_map(
            static fn (OrderGeneralStatus $status): string => DB::connection()->getPdo()->quote($status->value),
            [OrderGeneralStatus::Initial, OrderGeneralStatus::Draft],
        ));
        $orderConditions = "
            AND orders.sent_at IS NOT NULL
            AND orders.status NOT IN ({$excludedOrderStatuses})
            AND orders.is_test = 0
            AND (orders.is_cancelled = 0 OR orders.is_cancelled IS NULL)
        ";

        $orderTypeOrder = OrderType::Order->value;
        $invoiceTypeInvoice = OrderType::Invoice->value;
        $invoiceTypeDeposit = OrderType::DepositInvoice->value;

        $revenueExpr = function (string $condition) use ($orderConditions, $orderTypeOrder, $invoiceTypeInvoice, $invoiceTypeDeposit, $revenueSource): string {
            if ($revenueSource === 'invoice') {
                return "SUM(CASE WHEN {$condition} AND orders.type IN ('{$invoiceTypeInvoice}', '{$invoiceTypeDeposit}') {$orderConditions} THEN orders.company_sales_price_total ELSE 0 END)";
            }

            return "SUM(CASE WHEN {$condition} AND orders.type = '{$orderTypeOrder}' {$orderConditions} THEN orders.company_sales_price_total ELSE 0 END)";
        };
        $countsExpr = function () use ($orderConditions, $orderTypeOrder, $invoiceTypeInvoice, $invoiceTypeDeposit, $revenueSource): string {
            if ($revenueSource === 'invoice') {
                return "SUM(CASE WHEN orders.type IN ('{$invoiceTypeInvoice}', '{$invoiceTypeDeposit}') {$orderConditions} THEN 1 ELSE 0 END)";
            }

            return "SUM(CASE WHEN orders.type = '{$orderTypeOrder}' {$orderConditions} THEN 1 ELSE 0 END)";
        };

        if ($unit === 'month') {
            for ($m = 1; $m <= 12; $m++) {
                $key = ['january','february','march','april','may','june','july','august','september','october','november','december'][$m - 1];
                $condition = "MONTH(orders.sent_at) = {$m}";
                $selects[] = DB::raw($revenueExpr($condition) . " as {$key}");
            }
        } elseif ($unit === 'quarter') {
            for ($q = 1; $q <= 4; $q++) {
                $key = 'quarter_' . $q;
                $condition = "QUARTER(orders.sent_at) = {$q}";
                $selects[] = DB::raw($revenueExpr($condition) . " as {$key}");
            }
        } elseif ($unit === 'week') {
            // aggregate by week number across years (week 1..53)
            for ($w = 1; $w <= 53; $w++) {
                $key = 'week_' . str_pad($w, 2, '0', STR_PAD_LEFT);
                $condition = "WEEK(orders.sent_at, 3) = {$w}";
                $selects[] = DB::raw($revenueExpr($condition) . " as {$key}");
            }
        } else { // year
            $yearsRow = DB::table('orders')->selectRaw('MIN(YEAR(sent_at)) as minY, MAX(YEAR(sent_at)) as maxY')->first();
            $minY = (int) ($yearsRow->minY ?? date('Y') - 4);
            $maxY = (int) ($yearsRow->maxY ?? date('Y'));
            if ($maxY - $minY > 8) {
                $minY = $maxY - 8;
            }
            for ($y = $minY; $y <= $maxY; $y++) {
                $key = 'year_' . $y;
                $condition = "YEAR(orders.sent_at) = {$y}";
                $selects[] = DB::raw($revenueExpr($condition) . " as {$key}");
            }
        }

        // add total sum across all periods/orders
        $selects[] = DB::raw($revenueExpr('1=1') . " as total");

        // order count (use the counts closure properly)
        $selects[] = DB::raw($countsExpr() . " as orderCount");

        $query = Customer::query()
            ->select($selects)
            ->leftJoin('orders', function ($join): void {
                $join->on(
                    DB::raw('COALESCE(orders.billing_customer_id, orders.customer_id)'),
                    '=',
                    'customers.id',
                );
            })
            ->where('customers.status', CustomerStatus::Active->value)
            ->where('customers.type', '!=', CustomerType::AV->value)
            ->groupBy(
                'customers.id',
                'customers.type',
                'customers.name',
                'customers.first_name',
                'customers.middle_name',
                'customers.last_name',
                'customers.status',
            )
            ->havingRaw('(total > 0 OR orderCount > 0)');

        if ($segment !== 'all') {
            $query->where('customers.type', $segment);
        }

        return $query;
    }

    /**
     * Export all rows matching the current table filters (zelfde patroon als main-rapportage).
     */
    public function exportRevenueSpreadsheet(): ?BinaryFileResponse
    {
        return $this->exportRevenueRecordsToSpreadsheet(
            $this->getFilteredTableQuery()->orderBy('company_name')->cursor(),
        );
    }

    /**
     * Export the currently selected table rows to an XLSX file.
     */
    public function exportSelectedToExcel(): ?BinaryFileResponse
    {
        $records = $this->getSelectedTableRecords();

        if ($records->isEmpty()) {
            Notification::make()
                ->title('Geen rijen geselecteerd')
                ->warning()
                ->send();

            return null;
        }

        return $this->exportRevenueRecordsToSpreadsheet($records);
    }

    /**
     * @param  iterable<int, mixed>  $records
     */
    protected function exportRevenueRecordsToSpreadsheet(iterable $records): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        $filepath = null;
        $writer = null;

        try {
            $unit = $this->getUnit();
            $periodColumns = array_keys($this->getPeriodColumns($unit));
            $header = $this->buildRevenueExportHeaderRow($periodColumns);

            Storage::makeDirectory('exports');
            $filename = 'omzet_export_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
            $filepath = storage_path('app/exports/' . $filename);

            $totalsOrderCount = 0;
            $totalsPeriods = array_fill_keys($periodColumns, 0.0);
            $totalsGrand = 0.0;

            $xlsxOptions = new XlsxOptions();
            $xlsxOptions->DEFAULT_COLUMN_WIDTH = 16;

            $writer = new XlsxWriter($xlsxOptions);
            $writer->openToFile($filepath);
            $writer->addRow(Row::fromValues($header));

            foreach ($records as $record) {
                $row = [];
                $companyName = is_array($record) ? ($record['company_name'] ?? '') : ($record->company_name ?? '');
                $row[] = $companyName;

                $customerType = is_array($record) ? ($record['customer_type'] ?? '') : ($record->customer_type ?? '');
                $row[] = CustomerType::tryFrom((string) $customerType)?->getLabel() ?? $customerType;

                $orderCount = is_array($record) ? ($record['orderCount'] ?? 0) : ($record->orderCount ?? 0);
                $orderCount = (int) $orderCount;
                $row[] = $orderCount;
                $totalsOrderCount += $orderCount;

                foreach ($periodColumns as $key) {
                    $val = is_array($record) ? ($record[$key] ?? 0) : ($record->{$key} ?? 0);
                    $num = is_numeric($val) ? round((float) $val, 2) : 0.00;
                    $row[] = $num;
                    $totalsPeriods[$key] += $num;
                }

                $total = is_array($record) ? ($record['total'] ?? 0) : ($record->total ?? 0);
                $total = is_numeric($total) ? round((float) $total, 2) : 0.00;
                $row[] = $total;
                $totalsGrand += $total;

                $writer->addRow(Row::fromValues($row));
            }

            $totalsRow = ['Totalen', '', $totalsOrderCount];
            foreach ($periodColumns as $key) {
                $totalsRow[] = round($totalsPeriods[$key] ?? 0.0, 2);
            }
            $totalsRow[] = round($totalsGrand, 2);

            $writer->addRow(Row::fromValues($totalsRow));
            $writer->close();
            $writer = null;

            return response()->download($filepath)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            try {
                if ($writer instanceof \OpenSpout\Writer\WriterInterface) {
                    $writer->close();
                }
            } catch (\Throwable $_) {
                // ignore
            }

            if ($filepath !== null && file_exists($filepath)) {
                @unlink($filepath);
            }

            report($e);

            Notification::make()
                ->title('Export mislukt. Probeer het opnieuw.')
                ->danger()
                ->send();

            return null;
        }
    }

    /**
     * @param  list<string>  $periodColumns
     * @return list<string>
     */
    protected function buildRevenueExportHeaderRow(array $periodColumns): array
    {
        $revenueSource = $this->getRevenueSource();
        $header = [
            'Klant (factuur)',
            'Type',
            $revenueSource === 'invoice' ? 'Aantal facturen' : 'Aantal orders',
        ];

        foreach ($periodColumns as $key) {
            if (preg_match('/^january$|^february$|^march$|^april$|^may$|^june$|^july$|^august$|^september$|^october$|^november$|^december$/', $key)) {
                $header[] = self::MONTHS[$key] ?? ucfirst($key);

                continue;
            }

            if (str_starts_with($key, 'quarter_')) {
                $q = (int) str_replace('quarter_', '', $key);
                $header[] = 'Kwartaal ' . $q;

                continue;
            }

            if (str_starts_with($key, 'week_')) {
                $w = ltrim(str_replace('week_', '', $key), '0');
                $header[] = 'Week ' . (int) $w;

                continue;
            }

            if (str_starts_with($key, 'year_')) {
                $y = str_replace('year_', '', $key);
                $header[] = $y;

                continue;
            }

            $header[] = ucfirst($key);
        }

        $header[] = 'Totale omzet';

        return $header;
    }


    /**
     * Override of "getAllSelectableTableRecordsCount" method to show the correct total record count. The group by in the custom query caused issues.
     * @see \Filament\Tables\Concerns\HasBulkActions
     */
    public function getAllSelectableTableRecordsCount(): int
    {
        if ($this->getTable()->checksIfRecordIsSelectable()) {
            /** @var Collection $records */
            $records = $this->getTable()->selectsCurrentPageOnly() ?
                $this->getTableRecords() :
                $this->getFilteredTableQuery()->get();

            return $records
                ->filter(fn (\Illuminate\Database\Eloquent\Model | array $record): bool => $this->getTable()->isRecordSelectable($record))
                ->count();
        }

        if ($this->getTable()->selectsCurrentPageOnly()) {
            return $this->cachedTableRecords->count();
        }

        if ($this->cachedTableRecords instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return $this->cachedTableRecords->total();
        }

        // Changes:
        return $this->getFilteredTableQuery()?->get()->count() ?? $this->cachedTableRecords->count();
    }
}

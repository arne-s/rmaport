<?php

namespace App\Filament\Resources\MainReportingResource\Pages;

use App\Enums\OrderSubtype;
use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\MainReportingResource;
use App\Filament\Widgets\MainReportingTotalsWidget;
use App\Models\MainReport;
use App\Support\NavigationLink;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Filament\Forms\Components\Radio;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Resource;
use App\Filament\Support\ImportExportAuthorization;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListMainReports extends ListRecords
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

    protected static string $resource = MainReportingResource::class;

    protected static ?string $title = 'Main-rapportage';

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return array_merge(parent::getWidgetData(), $this->mainReportingTotalsForWidgets());
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return ['@xl' => 4, '!@lg' => 2];
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MainReportingTotalsWidget::class,
        ];
    }

    protected function mainReportingTimeUnit(): string
    {
        $state = $this->getTableFilterState('main_reporting_time_unit');

        return $state['time_unit'] ?? self::DEFAULT_TIME_UNIT;
    }

    /**
     * @return array{min: int, max: int}
     */
    protected function mainCreatedYearBounds(): array
    {
        $row = MainReport::query()
            ->whereNotNull('main_created_at')
            ->selectRaw('MIN(YEAR(main_created_at)) as min_y, MAX(YEAR(main_created_at)) as max_y')
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
    protected function mainReportingYearRadioOptions(): array
    {
        $bounds = $this->mainCreatedYearBounds();

        return collect(range($bounds['max'], $bounds['min']))
            ->mapWithKeys(fn(int $year): array => [(string) $year => (string) $year])
            ->prepend('Alle jaren', 'all')
            ->toArray();
    }

    /**
     * @return array<string, string>
     */
    protected function mainReportingPeriodRadioOptions(): array
    {
        $unit = $this->mainReportingTimeUnit();

        if ($unit === 'week') {
            $opts = collect(range(1, 53))
                ->mapWithKeys(fn(int $w): array => [(string) $w => 'Week ' . $w])
                ->toArray();

            return ['all' => 'Alle weken'] + $opts;
        }

        if ($unit === 'month') {
            $opts = collect(self::MONTH_PERIOD_LABELS)
                ->mapWithKeys(fn(string $label, int $m): array => [(string) $m => $label])
                ->toArray();

            return ['all' => 'Alle maanden'] + $opts;
        }

        if ($unit === 'quarter') {
            $opts = collect(range(1, 4))
                ->mapWithKeys(fn(int $q): array => [(string) $q => 'Kwartaal ' . $q])
                ->toArray();

            return ['all' => 'Alle kwartalen'] + $opts;
        }

        return ['all' => '—'];
    }

    protected function getMainReportingTimeUnitFilter(): Filter
    {
        return Filter::make('main_reporting_time_unit')
            ->label('Weergave')
            ->schema([
                ToggleFilter::make()
                    ->label(fn(array $state): string => ! empty($state['time_unit'])
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
            ->query(fn(Builder $query, array $data): Builder => $query);
    }

    protected function getMainReportingPeriodFilter(): Filter
    {
        $options = $this->mainReportingPeriodRadioOptions();

        return Filter::make('main_reporting_period')
            ->label('Periode')
            ->hidden(fn(): bool => $this->mainReportingTimeUnit() === 'year')
            ->schema([
                ToggleFilter::make()
                    ->label(fn(array $state): string => ! empty($state['period'])
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
                if ($this->mainReportingTimeUnit() === 'year') {
                    return $query;
                }

                $period = $data['period'] ?? 'all';
                if ($period === 'all' || $period === null || $period === '') {
                    return $query;
                }

                return match ($this->mainReportingTimeUnit()) {
                    'week' => $query->whereRaw('WEEK(main_created_at, 3) = ?', [(int) $period]),
                    'month' => $query->whereMonth('main_created_at', (int) $period),
                    'quarter' => $query->whereRaw('QUARTER(main_created_at) = ?', [(int) $period]),
                    default => $query,
                };
            });
    }

    protected function getMainReportingYearFilter(): Filter
    {
        $options = $this->mainReportingYearRadioOptions();
        $bounds = $this->mainCreatedYearBounds();
        $defaultYear = (string) $bounds['max'];

        return Filter::make('main_reporting_year')
            ->label('Jaar')
            ->schema([
                ToggleFilter::make()
                    ->label(fn(array $state): string => ! empty($state['year'])
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
                    $query->whereYear('main_created_at', (int) $year);
                }

                return $query;
            });
    }

    protected function getMainReportingSubtypeFilter(): Filter
    {
        return Resource::createStatusFilter(
            'subtype',
            'subtype',
            'Type',
            OrderSubtype::labels(),
        );
    }

    /**
     * @return array{
     *     saleTotal: float,
     *     purchaseFrameTotal: float,
     *     purchasePartsTotal: float,
     *     marginTotal: float
     * }
     */
    protected function mainReportingTotalsForWidgets(): array
    {
        $query = $this->getFilteredTableQuery();
        if (! $query) {
            return [
                'saleTotal' => 0.0,
                'purchaseFrameTotal' => 0.0,
                'purchasePartsTotal' => 0.0,
                'marginTotal' => 0.0,
            ];
        }

        $row = (clone $query)
            ->reorder()
            ->selectRaw(
                'COALESCE(SUM(sale_price_total), 0) as sale_total, ' .
                    'COALESCE(SUM(purchase_price_frame), 0) as purchase_frame_total, ' .
                    'COALESCE(SUM(purchase_price_parts), 0) as purchase_parts_total, ' .
                    'COALESCE(SUM(margin_price), 0) as margin_total'
            )
            ->first();

        return [
            'saleTotal' => (float) ($row->sale_total ?? 0),
            'purchaseFrameTotal' => (float) ($row->purchase_frame_total ?? 0),
            'purchasePartsTotal' => (float) ($row->purchase_parts_total ?? 0),
            'marginTotal' => (float) ($row->margin_total ?? 0),
        ];
    }

    /**
     * @return array<string|int, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.main-reporting.index') => 'Voortgang',
        ];
    }

    public function getHeader(): ?View
    {
        return view('filament.components.back-to-overview-with-topbar-breadcrumbs', [
            'title' => 'Dashboard',
            'url' => route('filament.app.pages.dashboard'),
            'class' => 'quote-overview-back mt-4 mb-[-15px]',
            'breadcrumbs' => Filament::hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Snapshot id')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('main_id')
                    ->label('Main id')
                    ->sortable()
                    ->formatStateUsing(fn (MainReport $record) => NavigationLink::main(
                        $record->main_id,
                        (string) $record->main_id,
                    ))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('order_uid')
                    ->label('Aanvraagnummer')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (MainReport $record) => NavigationLink::main(
                        $record->main_id,
                        $record->order_uid,
                    )),

                TextColumn::make('subtype')
                    ->label('Type')
                    ->formatStateUsing(fn (?OrderSubtype $state): string => $state?->getLabel() ?? '-')
                    ->sortable(),

                TextColumn::make('customer_debtor_number')
                    ->label('Klantnr.')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Klantnaam')
                    ->searchable()
                    ->url(fn (MainReport $record): ?string => $record->customer_id
                        ? route('filament.app.resources.customers.edit', ['record' => $record->customer_id])
                        : null)
                    ->color('primary')
                    ->toggleable(),

                TextColumn::make('dealer_name')
                    ->label('Factuurklant')
                    ->searchable()
                    ->url(fn (MainReport $record): ?string => $record->billing_customer_id
                        ? route('filament.app.resources.customers.edit', ['record' => $record->billing_customer_id])
                        : null)
                    ->color(fn (MainReport $record): ?string => $record->billing_customer_id ? 'primary' : null)
                    ->toggleable(),

                TextColumn::make('billing_customer_debtor_number')
                    ->label('Factuurklant nr.')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('chair_type')
                    ->label('Stoeltype')
                    ->toggleable(),

                TextColumn::make('supplier_name')
                    ->label('Fabrikant')
                    ->toggleable(),

                TextColumn::make('serial_number')
                    ->label('Serienummer')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('advisor_name')
                    ->label('Adviseur')
                    ->toggleable(),

                TextColumn::make('sale_price_total')
                    ->label('Verkoopprijs')
                    ->money('EUR', locale: 'nl')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('purchase_price_frame')
                    ->label('Inkoop frame')
                    ->money('EUR', locale: 'nl')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('purchase_price_parts')
                    ->label('Onderdelen inkoopprijs')
                    ->money('EUR', locale: 'nl')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('margin_price')
                    ->label('Marge')
                    ->money('EUR', locale: 'nl')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('invoice_user')
                    ->label('Betaler')
                    ->toggleable(),

                TextColumn::make('frame_purchase_order_at')
                    ->label('Frame PO (datum)')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('frame_purchase_order_month')
                    ->label('PO maand')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('frame_purchase_order_year')
                    ->label('PO jaar')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('frame_purchase_order_month_year')
                    ->label('PO maand-jaar')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('fitting_appointment_at')
                    ->label('Passing')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('quote_sent_at')
                    ->label('Offerte → klant')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('quote_approved_at')
                    ->label('Opdracht klant')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('order_sent_at')
                    ->label('Opdr.bev. → klant')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('ready_for_pickup_at')
                    ->label('Afleverklaar')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('delivered_at')
                    ->label('Afgeleverd')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('invoice_sent_at')
                    ->label('Factuur verstuurd')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Snapshot aangemaakt')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Snapshot bijgewerkt')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->deferFilters(false)
            ->filters([
                $this->getMainReportingTimeUnitFilter(),
                $this->getMainReportingPeriodFilter(),
                $this->getMainReportingYearFilter(),
                $this->getMainReportingSubtypeFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn(): ?BinaryFileResponse => $this->exportMainReportsSpreadsheet()),
            ])
            ->extraAttributes([
                'class' => '[&_td]:whitespace-nowrap',
            ])
            ->defaultSort('main_id', 'desc');
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function exportColumnDefinitions(): array
    {
        // Same columns and order as the default visible table (excluding toggle-hidden columns).
        return [
            ['key' => 'order_uid', 'label' => 'Aanvraagnummer'],
            ['key' => 'subtype', 'label' => 'Type'],
            ['key' => 'customer_debtor_number', 'label' => 'Klantnr.'],
            ['key' => 'customer_name', 'label' => 'Klantnaam'],
            ['key' => 'dealer_name', 'label' => 'Factuurklant'],
            ['key' => 'billing_customer_debtor_number', 'label' => 'Factuurklant nr.'],
            ['key' => 'chair_type', 'label' => 'Stoeltype'],
            ['key' => 'supplier_name', 'label' => 'Fabrikant'],
            ['key' => 'serial_number', 'label' => 'Serienummer'],
            ['key' => 'advisor_name', 'label' => 'Adviseur'],
            ['key' => 'sale_price_total', 'label' => 'Verkoopprijs'],
            ['key' => 'purchase_price_frame', 'label' => 'Inkoop frame'],
            ['key' => 'purchase_price_parts', 'label' => 'Inkoop onderdelen'],
            ['key' => 'margin_price', 'label' => 'Marge'],
            ['key' => 'invoice_user', 'label' => 'Betaler'],
            ['key' => 'frame_purchase_order_at', 'label' => 'Frame PO (datum)'],
            ['key' => 'frame_purchase_order_month_year', 'label' => 'PO maand-jaar'],
            ['key' => 'fitting_appointment_at', 'label' => 'Passing'],
            ['key' => 'quote_sent_at', 'label' => 'Offerte → klant'],
            ['key' => 'quote_approved_at', 'label' => 'Opdracht klant'],
            ['key' => 'order_sent_at', 'label' => 'Opdr.bev. → klant'],
            ['key' => 'ready_for_pickup_at', 'label' => 'Afleverklaar'],
            ['key' => 'delivered_at', 'label' => 'Afgeleverd'],
            ['key' => 'invoice_sent_at', 'label' => 'Factuur verstuurd'],
        ];
    }

    private function exportFormatAttribute(MainReport $record, string $key): float|int|string
    {
        $value = $record->getAttribute($key);

        if ($value === null) {
            return '';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('d/m/Y');
        }

        if (in_array($key, ['sale_price_total', 'purchase_price_frame', 'purchase_price_parts', 'margin_price'], true)) {
            return round((float) $value, 2);
        }

        if ($key === 'subtype') {
            $subtype = $value instanceof OrderSubtype
                ? $value
                : OrderSubtype::tryFrom((string) $value);

            return $subtype?->getLabel() ?? '';
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $keys
     * @return list<float|int|string>
     */
    private function mainReportToExportRow(MainReport $record, array $keys): array
    {
        $row = [];
        foreach ($keys as $key) {
            $row[] = $this->exportFormatAttribute($record, $key);
        }

        return $row;
    }

    public function exportMainReportsSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'main_rapportage_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        $filepath = storage_path('app/exports/' . $basename);

        $columns = $this->exportColumnDefinitions();
        $keys = array_map(fn(array $c): string => $c['key'], $columns);

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 16;

        $writer = new XlsxWriter($xlsxOptions);

        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues(array_map(fn(array $c): string => $c['label'], $columns)));

        $query = $this->getFilteredTableQuery()->orderBy('main_id', 'desc');

        foreach ($query->cursor() as $record) {
            if (! $record instanceof MainReport) {
                continue;
            }

            $writer->addRow(Row::fromValues($this->mainReportToExportRow($record, $keys)));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}

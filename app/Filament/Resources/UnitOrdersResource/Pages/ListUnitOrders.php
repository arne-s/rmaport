<?php

namespace App\Filament\Resources\UnitOrdersResource\Pages;

use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\UnitOrdersResource;
use App\Services\Reporting\UnitOrdersDeliveredPivotReport;
use Filament\Forms\Components\Radio;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListUnitOrders extends ListRecords
{
    protected static string $resource = UnitOrdersResource::class;

    protected static ?string $title = 'Bestelde units per jaar';

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.unit-orders.index') => 'Units per maand',
        ];
    }

    /**
     * Selected calendar year, or 'all' for every year in the report dataset.
     */
    protected function resolveReportYear(): int|string
    {
        $state = $this->getTableFilterState('year');
        $y = $state['year'] ?? null;

        if ($y === 'all') {
            return 'all';
        }

        if ($y === null || $y === '') {
            return UnitOrdersDeliveredPivotReport::defaultYearSelection();
        }

        $yi = (int) $y;
        $bounds = UnitOrdersDeliveredPivotReport::deliveredYearBounds();
        if ($yi < $bounds['min'] || $yi > $bounds['max']) {
            return UnitOrdersDeliveredPivotReport::defaultYearSelection();
        }

        return $yi;
    }

    protected function getYearFilter(): Filter
    {
        $options = UnitOrdersDeliveredPivotReport::yearRadioOptions();
        $defaultYear = UnitOrdersDeliveredPivotReport::defaultYearSelection();

        return Filter::make('year')
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
                            ->default($defaultYear)
                            ->afterStateUpdatedJs('location.reload();'),
                    ]),
            ])
            ->query(fn(Builder $query): Builder => $query);
    }

    public function table(Table $table): Table
    {
        $fmt = static fn($state): string => is_numeric($state)
            ? number_format((float) $state, 0, ',', '.')
            : (string) $state;

        return $table
            ->extraAttributes([
                'class' => 'max-w-[700px]',
            ])
            ->defaultSort('supplier_name', 'asc')
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back mt-1',
            ]))
            ->recordAction(null)
            ->recordUrl(null)
            ->records(function (?string $sortColumn, ?string $sortDirection): LengthAwarePaginator {
                $year = $this->resolveReportYear();
                $page = (int) $this->getTablePage();
                $perPage = (int) $this->getTableRecordsPerPage();

                return UnitOrdersDeliveredPivotReport::paginateGroupedRows(
                    $year,
                    $page,
                    $perPage > 0 ? $perPage : 50,
                    $this->getTablePaginationPageName(),
                    $sortColumn,
                    $sortDirection,
                );
            })
            ->columns(array_merge(
                [
                    TextColumn::make('supplier_name')
                        ->label('Fabrikant')
                        ->alignStart()
                        ->searchable(false)
                        ->sortable(),
                    TextColumn::make('chair_type')
                        ->label('Type unit')
                        ->alignStart()
                        ->searchable(false)
                        ->sortable(),
                ],
                collect(range(1, 12))
                    ->map(fn(int $m): TextColumn => TextColumn::make('m' . $m)
                        ->label((string) $m)
                        ->alignStart()
                        ->formatStateUsing($fmt)
                        ->sortable(false))
                    ->all(),
                [
                    TextColumn::make('row_total')
                        ->label('Eindtotaal')
                        ->alignStart()
                        ->formatStateUsing($fmt)
                        ->sortable(false),
                ],
            ))
            ->filters([
                $this->getYearFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->contentFooter(fn(): View => view('filament.resources.unit-orders.table-footer', [
                'grand' => UnitOrdersDeliveredPivotReport::grandTotals($this->resolveReportYear()),
                'grandDiff' => UnitOrdersDeliveredPivotReport::grandTotalsYearOverYearDiff($this->resolveReportYear()),
                'formatQty' => $fmt,
                'formatDiff' => static fn(int $v): string => $v > 0 ? '+' . $v : (string) $v,
            ]));
    }
}

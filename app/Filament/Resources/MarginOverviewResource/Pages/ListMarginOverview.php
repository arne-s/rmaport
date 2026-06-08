<?php

namespace App\Filament\Resources\MarginOverviewResource\Pages;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Enums\FiltersLayout;
use App\Enums\OrderType;
use App\Filament\Resources\MarginOverviewResource;
use App\Filament\Support\ImportExportAuthorization;
use Illuminate\Contracts\View\View;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use App\Filament\Tables\Columns\{
    OrderMarginsColumn,
    OrderNumberPageColumn,
    PaidColumn,
};
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListMarginOverview extends ListRecords
{
    protected static string $resource = MarginOverviewResource::class;

    protected static ?string $title = 'Marge orders';

    protected static ?string $breadcrumb = 'Marge orders';

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return [
            '' => 'Reporting',
            route('filament.app.resources.margin-overview.index') => 'Marge orders',
        ];
    }

    protected function getTableQuery(): Builder
    {
        return BaseOrder::query()
            ->select('orders.*')
            ->where('orders.type', OrderType::Order)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('orders as margin_mains')
                    ->where('margin_mains.type', OrderType::Main->value)
                    ->whereNotNull('margin_mains.order_id')
                    ->whereColumn('margin_mains.order_id', 'orders.id');
            })
            ->leftJoin('orders as margin_request_main', function ($join): void {
                $join->on('margin_request_main.id', '=', 'orders.main_id')
                    ->where('margin_request_main.type', OrderType::Main->value);
            });
    }

    public function getTableRecords(): Collection
    {
        return parent::getTableRecords()
            ->unique('id')
            ->map(function (BaseOrder $order): BaseOrder {
                $order->admin_margin_summary = $order->getSpMarginSummaryAttribute();
                $order->companySalesPrice = $order->getCompanySalesPriceTotal();

                return $order;
            });
    }

    private function resolveMarginTableMain(Model $record): ?Main
    {
        if (! $record instanceof BaseOrder) {
            return null;
        }

        $fromBelongsTo = $record->main_id !== null ? $record->main : null;

        $fromOrderId = null;
        if ($record->getKey() !== null) {
            $fromOrderId = Main::query()->where('order_id', $record->getKey())->first();
        }

        return $fromBelongsTo ?? $fromOrderId;
    }

    public function getTableRecordKey(Model|array $record): string
    {
        if ($record instanceof BaseOrder) {
            return (string) $record->getKey();
        }

        return '';
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_main_uid')
                    ->label('Aanvraagnummer')
                    ->state(function (Model $record): string {
                        $main = $this->resolveMarginTableMain($record);
                        $uid = $main?->getUid();
                        if ($main === null || ! filled($uid)) {
                            return '__margin_no_request_main__';
                        }

                        return json_encode([$main->getId(), $uid], JSON_THROW_ON_ERROR);
                    })
                    ->formatStateUsing(function (string $state): HtmlString|string {
                        if ($state === '__margin_no_request_main__') {
                            return '';
                        }
                        try {
                            $decoded = json_decode($state, true, 2, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            return '';
                        }
                        if (! is_array($decoded) || count($decoded) !== 2) {
                            return '';
                        }
                        [$mainId, $uid] = $decoded;
                        $url = route('filament.app.resources.mains.view', ['record' => (int) $mainId]);

                        return new HtmlString(
                            '<a class="main-request-number-link hover:underline" href="'.e($url).'">'.e($uid).'</a>',
                        );
                    })
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        'margin_request_main.uid',
                        'like',
                        '%'.$search.'%',
                    ))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';

                        return $query->orderBy('margin_request_main.uid', $dir);
                    }),

                OrderNumberPageColumn::make('order.uid')
                    ->label('Ordernummer')
                    ->searchable(['orders.uid'])
                    ->sortable(['orders.uid']),

                PaidColumn::make('paid_at')
                    ->label('Betaald')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtoupper($direction) === 'DESC' ? 'desc' : 'asc';

                        return $query->orderBy('orders.paid_at', $dir);
                    })
                    ->extraHeaderAttributes(['class' => 'paddingRight'])
                    ->extraCellAttributes(['class' => 'paddingRight']),

                TextColumn::make('companySalesPrice')
                    ->label('Verkoop')
                    ->formatStateUsing(fn ($state) => '€' . number_format(round($state, 2), 2, ',', '.'))
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                TextColumn::make('company_purchase_price_total')
                    ->label('Inkoop')
                    ->formatStateUsing(fn ($state) => '€' . number_format(round($state, 2), 2, ',', '.'))
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),

                TextColumn::make('admin_margin_summary')
                    ->label('Marge | Beheer'),

                OrderMarginsColumn::make('order_margins')
                    ->label('Marges')
                    ->extraHeaderAttributes(['class' => 'borderLeft'])
                    ->extraCellAttributes(['class' => 'borderLeft']),
            ])
            ->deferFilters(false)
            ->filters([], layout: FiltersLayout::AboveContent)
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportMarginOverviewSpreadsheet()),
            ]);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function marginExportColumnDefinitions(): array
    {
        return [
            ['key' => 'request_main_uid', 'label' => 'Aanvraagnummer'],
            ['key' => 'order_uid', 'label' => 'Ordernummer'],
            ['key' => 'paid', 'label' => 'Betaald'],
            ['key' => 'company_sales', 'label' => 'Verkoop'],
            ['key' => 'company_purchase', 'label' => 'Inkoop'],
            ['key' => 'admin_margin_summary', 'label' => 'Marge | Beheer'],
        ];
    }

    /**
     * @return list<string|float|int>
     */
    private function marginRecordToExportRow(Model $record): array
    {
        $main = $this->resolveMarginTableMain($record);
        $requestMainUid = (string) ($main?->getUid() ?? $main?->uid ?? '');

        $orderUid = $record instanceof BaseOrder
            ? (string) ($record->getUid() ?? $record->uid ?? '')
            : '';

        $paidLabel = '';
        if ($record instanceof BaseOrder) {
            $paidAt = $record->paid_at;
            if ($paidAt instanceof CarbonInterface) {
                $paidLabel = 'Ja, '.$paidAt->format('d/m/Y');
            } else {
                $paidLabel = 'Nee';
            }
        }

        $salesTotal = round((float) ($record->companySalesPrice ?? 0), 2);
        $purchaseTotal = round((float) ($record->company_purchase_price_total ?? 0), 2);
        $adminMarginSummary = (string) ($record->admin_margin_summary ?? '');

        return [
            $requestMainUid,
            $orderUid,
            $paidLabel,
            $salesTotal,
            $purchaseTotal,
            $adminMarginSummary,
        ];
    }

    public function exportMarginOverviewSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'margin_orders_'.now()->format('Ymd_His').'_'.Str::random(6).'.xlsx';
        $filepath = storage_path('app/exports/'.$basename);

        $columns = $this->marginExportColumnDefinitions();

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 18;
        $xlsxOptions->setColumnWidthForRange(32, 10, 10);
        $xlsxOptions->setColumnWidthForRange(32, 12, 12);
        $xlsxOptions->setColumnWidthForRange(28, 14, 14);

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues(array_map(fn (array $c): string => $c['label'], $columns)));

        foreach ($this->getTableRecords() as $record) {
            $writer->addRow(Row::fromValues($this->marginRecordToExportRow($record)));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
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
}

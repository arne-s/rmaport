<?php

namespace App\Filament\Resources\SerialNumbersResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Resource;
use App\Filament\Resources\SerialNumbersResource;
use App\Filament\Support\ImportExportAuthorization;
use App\Enums\OrderSubtype;
use App\Models\Order\Main;
use App\Models\Product;
use App\Models\SerialNumber;
use App\Support\NavigationLink;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListSerialNumbers extends ListRecords
{
    protected static string $resource = SerialNumbersResource::class;

    protected static ?string $breadcrumb = 'Serienummers';

    protected static ?string $title = 'Serienummers';

    protected function baseSerialNumbersQuery(): Builder
    {
        // Live display name: owner record when linked, else customer resolved by debtor number, else snapshot column.
        $customerNameAlias = static function (string $alias): string {
            return "NULLIF(TRIM(CONCAT(COALESCE({$alias}.first_name,''), ' ', COALESCE({$alias}.middle_name,''), ' ', COALESCE({$alias}.last_name,''))), '')";
        };

        $customerNameSql = 'COALESCE(
            '.$customerNameAlias('sn_owner_customer').',
            '.$customerNameAlias('sn_debtor_customer').',
            serial_numbers.customer_name
        )';

        // Set when the main order is fully delivered; fall back to created_at until then.
        $deliverySql = 'CASE WHEN serial_numbers.order_id IS NOT NULL THEN COALESCE(serial_numbers.delivered_at, serial_numbers.created_at) ELSE NULL END';

        $debtorNormInner = DB::table('customers')
            ->select([
                'customers.id',
                DB::raw("TRIM(COALESCE(customers.debtor_number, '')) AS debtor_trim"),
            ])
            ->whereRaw("TRIM(COALESCE(customers.debtor_number, '')) <> ''");

        $debtorLookupSubquery = DB::query()
            ->fromSub($debtorNormInner, 'debtor_norm')
            ->select([
                DB::raw('MIN(debtor_norm.id) AS match_customer_id'),
                'debtor_norm.debtor_trim',
            ])
            ->groupBy('debtor_norm.debtor_trim');

        return SerialNumber::query()
            ->where('serial_numbers.order_sub_type', OrderSubtype::Unit->value)
            ->select([
                'serial_numbers.id',
                'serial_numbers.serial_number',
                'serial_numbers.owner_id',
                'serial_numbers.order_id',
                'serial_numbers.main_id',
                'serial_numbers.created_at',
                'serial_numbers.delivered_at',
                'serial_numbers.name',
                'serial_numbers.type',
                'serial_numbers.customer_debtor_number',
                'serial_numbers.order_number',
                'serial_numbers.order_date',
                'serial_numbers.total_price_inc',
                DB::raw('(
                    SELECT COALESCE(SUM(sn_ledger.total_price_inc), 0)
                    FROM serial_numbers AS sn_ledger
                    WHERE sn_ledger.serial_number = serial_numbers.serial_number
                ) AS tco_total'),
                DB::raw("({$customerNameSql}) as customer_name_display"),
                DB::raw("({$deliverySql}) as delivery_display_at"),
                DB::raw('COALESCE(sn_owner_customer.id, sn_debtor_customer.id) as resolved_customer_id'),
                DB::raw('serial_main_uid_order.uid as main_uid_display'),
            ])
            ->leftJoin('orders as serial_main_uid_order', 'serial_main_uid_order.id', '=', 'serial_numbers.main_id')
            ->leftJoin('customers as sn_owner_customer', 'sn_owner_customer.id', '=', 'serial_numbers.owner_id')
            ->leftJoinSub($debtorLookupSubquery, 'sn_debtor_lookup', function (JoinClause $join): void {
                $join->whereRaw(
                    "TRIM(COALESCE(serial_numbers.customer_debtor_number, '')) = sn_debtor_lookup.debtor_trim"
                )->whereRaw("TRIM(COALESCE(serial_numbers.customer_debtor_number, '')) <> ''");
            })
            ->leftJoin('customers as sn_debtor_customer', 'sn_debtor_customer.id', '=', 'sn_debtor_lookup.match_customer_id')
            ->with([
                'main.customer.shippingAddress',
                'main.customer.billingAddress',
                'order.main.customer.shippingAddress',
                'order.main.customer.billingAddress',
            ]);
    }

    /**
     * Same customer label as elsewhere in the app ({@see Main::getCustomerAddressDisplayName()}), with legacy fallback.
     */
    private function resolveCustomerDisplayNameForSerialNumber(SerialNumber $record): string
    {
        $main = null;
        if ($record->main_id !== null) {
            $record->loadMissing([
                'main.customer.shippingAddress',
                'main.customer.billingAddress',
            ]);
            $main = $record->main;
        }

        if ($main === null && $record->order_id !== null) {
            $record->loadMissing([
                'order.main.customer.shippingAddress',
                'order.main.customer.billingAddress',
            ]);
            $main = $record->order?->main;
        }

        if ($main instanceof Main) {
            $display = $main->getCustomerAddressDisplayName();
            if ($display !== '') {
                return $display;
            }
        }

        return (string) ($record->customer_name_display ?? '');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseSerialNumbersQuery())
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back mt-1',
            ]))
            ->columns([
                TextColumn::make('order_date')
                    ->label('Orderdatum')
                    ->formatStateUsing(function ($state): ?string {
                        if ($state === null || $state === '') {
                            return null;
                        }

                        return Carbon::parse($state)->format('d/m/Y');
                    })
                    ->sortable(),

                TextColumn::make('customer_name_display')
                    ->label('Klantnaam')
                    ->formatStateUsing(fn (mixed $state, SerialNumber $record): string => $this->resolveCustomerDisplayNameForSerialNumber($record))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = "%{$search}%";

                        return $query->where(function (Builder $q) use ($like): void {
                            $q->where(function (Builder $ownerLike) use ($like): void {
                                $ownerLike->where('sn_owner_customer.first_name', 'like', $like)
                                    ->orWhere('sn_owner_customer.last_name', 'like', $like)
                                    ->orWhere('sn_owner_customer.middle_name', 'like', $like)
                                    ->orWhereRaw(
                                        "TRIM(CONCAT(COALESCE(sn_owner_customer.first_name,''), ' ', COALESCE(sn_owner_customer.middle_name,''), ' ', COALESCE(sn_owner_customer.last_name,''))) like ?",
                                        [$like],
                                    );
                            })->orWhere(function (Builder $debtorLike) use ($like): void {
                                $debtorLike->where('sn_debtor_customer.first_name', 'like', $like)
                                    ->orWhere('sn_debtor_customer.last_name', 'like', $like)
                                    ->orWhere('sn_debtor_customer.middle_name', 'like', $like)
                                    ->orWhereRaw(
                                        "TRIM(CONCAT(COALESCE(sn_debtor_customer.first_name,''), ' ', COALESCE(sn_debtor_customer.middle_name,''), ' ', COALESCE(sn_debtor_customer.last_name,''))) like ?",
                                        [$like],
                                    );
                            })
                                ->orWhere('serial_numbers.customer_name', 'like', $like);
                        });
                    })
                    ->url(fn (SerialNumber $record): ?string => filled($record->resolved_customer_id ?? null)
                        ? CustomerResource::getEditUrlWithTab($record->resolved_customer_id)
                        : null)
                    ->color('primary'),

                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => Product::getFrameChairTypeLabel($state))
                    ->searchable(['serial_numbers.type'])
                    ->sortable(['serial_numbers.type']),

                TextColumn::make('name')
                    ->label('Unit naam')
                    ->searchable(['serial_numbers.name'])
                    ->sortable(),

                TextColumn::make('serial_number')
                    ->label('Serienummer')
                    ->searchable(['serial_numbers.serial_number'])
                    ->sortable(),

                TextColumn::make('main_uid_display')
                    ->label('Aanvraagnummer')
                    ->placeholder('—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('serial_main_uid_order.uid', 'like', "%{$search}%");
                    })
                    ->sortable(['serial_main_uid_order.uid'])
                    ->formatStateUsing(fn (SerialNumber $record) => NavigationLink::main(
                        filled($record->main_id) ? (int) $record->main_id : null,
                        $record->main_uid_display,
                        '—',
                    )),

                TextColumn::make('order_number')
                    ->label('Ordernummer')
                    ->searchable(['serial_numbers.order_number'])
                    ->sortable(),

                TextColumn::make('tco_total')
                    ->label('TCO (incl. BTW)')
                    ->money('EUR', locale: 'nl')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('tco_total', $direction);
                    }),
            ])
            ->deferFilters(false)
            ->filters([
                Resource::createStatusFilter(
                    'type',
                    'serial_numbers.type',
                    'Type',
                    Product::frameChairTypeOptions(),
                ),
            ], layout: FiltersLayout::AboveContent)
            ->defaultSort('serial_numbers.order_date', 'desc')
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportSerialNumbersSpreadsheet()),
            ])
            ->extraAttributes([
                'class' => '[&_td]:whitespace-nowrap',
            ]);
    }

    public function exportSerialNumbersSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'serienummers_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        $filepath = storage_path('app/exports/' . $basename);

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 20;

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues([
            'Serienummer',
            'Aanvraagnummer',
            'Klantnaam',
            'Debiteurnummer',
            'Unit naam',
            'Ordernummer',
            'Orderdatum',
            'Datum (afgeleverd)',
            'TCO (incl. BTW)',
        ]));

        $query = $this->getFilteredTableQuery()->orderBy('serial_numbers.serial_number');

        foreach ($query->cursor() as $record) {
            if (! $record instanceof SerialNumber) {
                continue;
            }

            $delivery = $record->delivery_display_at ?? null;
            $deliveryStr = $delivery !== null && $delivery !== ''
                ? Carbon::parse($delivery)->format('d/m/Y')
                : '';

            $orderDate = $record->order_date ?? null;
            $orderDateStr = $orderDate !== null && $orderDate !== ''
                ? Carbon::parse($orderDate)->format('d/m/Y')
                : '';

            $writer->addRow(Row::fromValues([
                (string) ($record->serial_number ?? ''),
                (string) ($record->main_uid_display ?? ''),
                $this->resolveCustomerDisplayNameForSerialNumber($record),
                (string) ($record->customer_debtor_number ?? ''),
                (string) ($record->name ?? ''),
                (string) ($record->order_number ?? ''),
                $orderDateStr,
                $deliveryStr,
                round((float) ($record->tco_total ?? $record->total_price_inc ?? 0), 2),
            ]));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}

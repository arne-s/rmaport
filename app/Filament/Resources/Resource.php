<?php

namespace App\Filament\Resources;

use App\Enums\CustomerType;
use App\Enums\MailLogStatus;
use App\Enums\NoteStatus;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use Filament\Schemas\Components\Grid;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseOrderType;
use App\Filament\Forms\Components\ToggleFilter;
use App\Models\Customer;
use App\Models\Supplier;
use Carbon\Carbon;
use Exception;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

abstract class Resource extends \Filament\Resources\Resource
{
    public static function configureTable(Table $table): void
    {
        parent::configureTable($table);

        $table->columnManager(false);
    }

    /**
     * Get the "Type" filter for the resource table.
     *
     * @return Filter
     * @throws Exception
     */
    public static function getTypeFilter(): Filter
    {
        $t = [
            'quote' => 'Offerte',
            'order' => 'Order',
            'deposit_invoice' => 'Aanbetalingsfactuur',
            'invoice' => 'Slotfactuur',
            'credit_invoice' => 'Creditfactuur',
        ];
        return Filter::make('type')
            ->label('Type')
            ->schema([
                ToggleFilter::make()
                    ->label('Type')
                    ->schema([
                        CheckboxList::make('type')
                            ->searchable(false)
                            ->label('')
                            ->options([
                                'quote' => $t['quote'],
                                'order' => $t['order'],
                                'deposit_invoice' => $t['deposit_invoice'],
                                'invoice' => $t['invoice'],
                                'credit_invoice' => $t['credit_invoice'],
                            ]),
                    ])
            ])
            ->indicateUsing(function (array $data) use ($t): ?string {
                if (!$data['type']) {
                    return null;
                }
                $data['type'] = array_map(fn($v) => $t[$v], $data['type']);
                return 'Type: ' . implode(', ', $data['type'] ?? []);
            })
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['type'], fn(Builder $query, $ids) => $query
                    ->whereIn('type', $ids)
                )
            );
    }

    /**
     * Redirect URL to the main order view when the record belongs to a main, e.g. after save/cancel.
     * Used on quote/order edit pages so saving or cancelling returns to the main (aanvraag) when the record has main_id.
     */
    public static function getRedirectToMainUrlForRecord(?object $record): ?string
    {
        if ($record === null) {
            return null;
        }
        $mainId = $record->main_id ?? null;
        if ($mainId === null || $mainId === '') {
            return null;
        }

        return route('filament.app.resources.mains.view', ['record' => (int) $mainId]);
    }

    /**
     * Get the "Status" filter for the resource table.
     *
     * @param array $options
     * @param string $default
     * @return Filter
     * @throws Exception
     */
    public static function getStatusFilter(array $options, string $default = ''): Filter
    {
        if (!$options) {
            $options = [
                'pending' => 'Openstaand',
                'completed' => 'Gerealiseerd',
            ];
        }


        $checkboxlist = CheckboxList::make('status')
            ->searchable(false)
            ->hiddenLabel()
            ->options($options);

        if (!empty($default)) {
            $checkboxlist->default([$default]);
        }

        return Filter::make('status')
            ->label('Status')
            ->schema([
                ToggleFilter::make()
                    ->label('Status')
                    ->schema([
                        $checkboxlist

                    ])
            ])
            ->indicateUsing(function (array $data): ?string {
                if (!$data['status']) {
                    return null;
                }

                $translations = [
                    'pending' => 'openstaand',
                    'completed' => 'gerealiseerd',
                    'expired' => 'verlopen',
                    'changed' => 'aangepast',
                    'canceled' => 'geannuleerd',
                ];

                foreach ($data['status'] as &$status) {
                    $status = $translations[$status];
                }
                return 'Status: ' . implode(', ', $data['status'] ?? []);
            })
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['status'], fn(Builder $query, $ids) => $query
                    ->whereIn('status', $ids)
                )
            );
    }

    /**
     * Get the "getActiveFilter" filter for the resource table.
     *
     * @return Filter
     * @throws Exception
     */
    public static function getActiveFilter(): Filter
    {
        return Filter::make('is_active')
            ->label('Status')
            ->schema([
                ToggleFilter::make()
                    ->label('Status')
                    ->schema([
                        CheckboxList::make('is_active')
                            ->searchable(false)
                            ->label('')
                            ->options([
                                '1' => 'Actief',
                                '0' => 'Concept',
                            ]),
                    ])
            ])
            ->indicateUsing(function (array $data): ?string {
                if (!$data['is_active']) {
                    return null;
                }
                return 'Status: ' . implode(', ', $data['is_active'] ?? []);
            })
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['is_active'], fn(Builder $query, $value) => $query
                    ->whereIn('is_active', $value)
                )
            );
    }

    /**
     * Get the "Date" filter for the resource table.
     *
     * @return Filter
     * @throws Exception
     */
    public static function getDateFilter(): Filter
    {
        return Filter::make('created_at')
            ->label('Datum')
            ->indicateUsing(function (array $data): ?string {
                $from = $data['created_from'];
                $to = $data['created_to'];
                if ($from && !$to) {
                    return 'Datum: vanaf ' . Carbon::parse($from)->translatedFormat('j F Y');
                } else if (!$from && $to) {
                    return 'Datum: tot ' . Carbon::parse($to)->translatedFormat('j F Y');
                } else if ($from && $to) {
                    return 'Datum: tussen ' .
                        Carbon::parse($from)->translatedFormat('j F Y') .
                        ' en ' . Carbon::parse($to)->translatedFormat('j F Y');
                }
                return null;
            })
            ->schema([
                ToggleFilter::make()
                    ->schema([
                        Grid::make()
                            ->columns(1)
                            ->extraAttributes(['class' => 'divide-y-2 divide-gray-200'])
                            ->schema([
                                DatePicker::make('created_from')
                                    ->placeholder('Datum van')
                                    ->hiddenLabel()
                                    ->native(false)
                                    ->suffixIcon(Heroicon::Calendar),
                            ]),
                        Grid::make()
                            ->columns(1)
                            ->schema([
                                DatePicker::make('created_to')
                                    ->placeholder('Datum tot')
                                    ->hiddenLabel()
                                    ->native(false)
                                    ->suffixIcon(Heroicon::Calendar),
                            ]),
                    ])
                    ->label('Datum van/tot'),
            ])
            ->query(function (Builder $query, array &$data): Builder {
                return $query
                    ->when($data['created_from'], fn(Builder $query, $date): Builder => $query
                        ->whereDate('created_at', '>=', $date)
                    )
                    ->when($data['created_to'], fn(Builder $query, $date): Builder => $query
                        ->whereDate('created_at', '<=', $date)
                    );
            });
    }


    /**
     * Get the "Dealer" filter for the resource table (filters by billing_customer_id on Dealer-type customers).
     *
     * @param null $fromRelation
     * @return Filter
     * @throws Exception
     */
    public static function getDealerFilter($fromRelation = null): Filter
    {
        return Filter::make('billing_customer_id')
            ->label('Dealer')
            ->indicateUsing(function (array $data): ?string {
                if (empty($data['billing_customer_id'])) {
                    return null;
                }
                $list = Customer::query()
                    ->whereIn('id', $data['billing_customer_id'])
                    ->get()
                    ->map(fn(Customer $c) => $c->getName());
                if (count($list) > 1) {
                    $str = $list->slice(0, 1)->join(', ') . ' (+' . (count($list) - 1) . ')';
                } else {
                    $str = $list->join(', ');
                }

                return 'Dealer: ' . $str;
            })
            ->schema([
                ToggleFilter::make()
                    ->label('Dealer')
                    ->schema([
                        CheckboxList::make('billing_customer_id')
                            ->searchable(false)
                            ->label('')
                            ->options(fn (): array => Customer::query()
                                ->where('type', CustomerType::Dealer->value)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Customer $c): array => [$c->getId() => $c->getName() ?? 'Dealer #'.$c->getId()])
                                ->all()
                            ),
                    ]),
            ])
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['billing_customer_id'] ?? null, fn(Builder $query, $ids) => $query
                    ->whereIn('billing_customer_id', $ids)
                )
            );
    }


    /**
     * Get the "Supplier" filter for the resource table.
     *
     * @return Filter
     * @throws Exception
     */

    public static function getSupplierFilter(?string $relationshipColumn = null): Filter
    {
        return Filter::make('supplier_id')
            ->label('Leverancier')
            ->indicateUsing(function (array $data): ?string {
                if (empty($data['supplier_id'])) {
                    return null;
                }

                $list = Supplier::query()
                    ->whereIn('id', $data['supplier_id'])
                    ->pluck('name');

                if (count($list) > 1) {
                    $str = $list->slice(0, 1)->join(', ') . ' (+' . (count($list) - 1) . ')';
                } else {
                    $str = $list->join(', ');
                }

                return 'Leverancier: ' . $str;
            })
            ->schema([
                ToggleFilter::make()
                    ->label('Leverancier')
                    ->schema([
                        CheckboxList::make('supplier_id')
                            ->searchable(false)
                            ->label('')
                            ->options(fn() => Supplier::query()
                                ->pluck('name', 'id')
                                ->all()
                            ),
                    ]),
            ])
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when(!empty($data['supplier_id']), fn(Builder $query) => $query->whereHas($relationshipColumn ?? 'product.supplier', fn(Builder $q) => $q->whereIn('id', $data['supplier_id']))
                )
            );
    }

    /**
     * Get the "OrderStatus" filter for the resource table.
     *
     * @param array $options
     * @param string $default
     * @return Filter
     * @throws Exception
     */
    public static function getOrderStatusFilter(array $options, string $default = '', bool $disabled = false): Filter
    {
        if (!$options) {
            $options = OrderStatus::labels();
        }

        $checkboxlist = CheckboxList::make('order_status')
            ->searchable(false)
            ->label('')
            ->options($options);

        if ($default !== '') {
            $checkboxlist->default([$default]);
        }

        $toggle = ToggleFilter::make()
            ->label('Status')
            ->schema([$checkboxlist]);

        if ($disabled) {
            $toggle->extraAttributes([
                'aria-disabled' => 'true',
            ]);
        }

        $filter = Filter::make('order_status')
            ->label('Status')
            ->schema([$toggle])
            ->indicateUsing(function (array $data) use ($options, $disabled): ?string {
                if ($disabled || empty($data['order_status'])) {
                    return null;
                }
                return 'Status: ' . implode(', ', array_map(fn($s) => $options[$s] ?? $s, $data['order_status']));
            });

        return $filter->query(
            fn(Builder $query, array $data): Builder => $query->when($data['order_status'] ?? null, fn(Builder $q, $ids) => $q->whereIn('order_status', $ids))
        );
    }

    /**
     * Get the "OrderStatus" filter with only the sub-statuses of the given main status (see OrderStatus::getSubStatuses).
     */
    public static function getOrderStatusFilterForSubStatuses(OrderStatus $mainStatus, string $default = '', bool $disabled = false): Filter
    {
        $subStatuses = OrderStatus::getSubStatuses($mainStatus);
        $options = array_reduce($subStatuses, function (array $carry, OrderStatus $s): array {
            $carry[$s->value] = $s->getLabel() ?? $s->value;

            return $carry;
        }, []);
        if (! array_key_exists($mainStatus->value, $options)) {
            $options = [$mainStatus->value => $mainStatus->getLabel() ?? $mainStatus->value] + $options;
        }

        return self::getOrderStatusFilter($options, $default, $disabled);
    }

    /**
     * Get the "NoteStatus" filter for the resource table.
     *
     * @param array $options
     * @param string $default
     * @return Filter
     */
    public static function getNoteStatusFilter(array $options = [], string $default = ''): Filter
    {
        if (! $options) {
            $options = NoteStatus::labels();
        }

        $checkboxlist = CheckboxList::make('status')
            ->searchable(false)
            ->label('')
            ->options($options);

        if ($default !== '') {
            $checkboxlist->default([$default]);
        }

        $toggle = ToggleFilter::make()
            ->label('Status')
            ->schema([$checkboxlist]);

        $filter = Filter::make('status')
            ->label('Status')
            ->schema([$toggle])
            ->indicateUsing(function (array $data) use ($options): ?string {
                if (empty($data['status'])) {
                    return null;
                }
                return 'Status: ' . implode(', ', array_map(fn ($s) => $options[$s] ?? $s, $data['status']));
            });

        return $filter->query(
            fn (Builder $query, array $data): Builder => $query->when($data['status'] ?? null, fn (Builder $q, $ids) => $q->whereIn('status', $ids))
        );
    }

    /**
     * Get the "PurchaseOrderStatus" filter for the resource table.
     *
     * @param array $options
     * @param string $default
     * @return Filter
     * @throws Exception
     */
    public static function getPurchaseOrderStatusFilter(array $options, string $default = ''): Filter
    {
        if (!$options) {
            $options = PurchaseOrderStatus::visibleStatuses();
        }

        $checkboxlist = CheckboxList::make('status')
            ->searchable(false)
            ->label('')
            ->options($options);

        if (!empty($default)) {
            $checkboxlist->default([$default]);
        }

        return Filter::make('status')
            ->label('Status')
            ->schema([
                ToggleFilter::make()
                    ->label('Status')
                    ->schema([
                        $checkboxlist
                    ])
            ])
            ->indicateUsing(function (array $data) use ($options): ?string {
                if (!$data['status']) {
                    return null;
                }

                foreach ($data['status'] as &$status) {
                    $status = $options[$status];
                }
                return 'Status: ' . implode(', ', $data['status'] ?? []);
            })
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['status'], fn(Builder $query, $ids) => $query
                    ->whereIn('status', $ids)
                )
            );
    }


    /**
     * Get the "MailLogStatus" filter for the resource table.
     *
     * @param array $options
     * @param string $default
     * @return Filter
     * @throws Exception
     */
    public static function getMailLogStatusFilter(?array $options = null, string $default = ''): Filter
    {
        $options = MailLogStatus::labels();

        $checkboxlist = CheckboxList::make('status')
            ->searchable(false)
            ->label('')
            ->options($options);

        if (!empty($default)) {
            $checkboxlist->default([$default]);
        }

        return Filter::make('status')
            ->label('Status')
            ->schema([
                ToggleFilter::make()
                    ->label('Status')
                    ->schema([
                        $checkboxlist
                    ])
            ])
            ->indicateUsing(function (array $data) use ($options): ?string {
                if (!$data['status']) {
                    return null;
                }

                foreach ($data['status'] as &$status) {
                    $status = $options[$status];
                }
                return 'Status: ' . implode(', ', $data['status'] ?? []);
            })
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['status'], fn(Builder $query, $ids) => $query
                    ->whereIn('status', $ids)
                )
            );
    }



    /**
     * Get the "PurchaseOrderType" filter for the resource table.
     *
     * @param array $options
     * @param string $default
     * @return Filter
     * @throws Exception
     */
    public static function getPurchaseOrderTypeFilter(?array $options = null, string $default = ''): Filter
    {
        if (!$options) {
            $options = PurchaseOrderType::labels();
        }

        $checkboxlist = CheckboxList::make('type')
            ->searchable(false)
            ->label('')
            ->options($options);

        if (!empty($default)) {
            $checkboxlist->default([$default]);
        }

        return Filter::make('type')
            ->label('Type')
            ->schema([
                ToggleFilter::make()
                    ->label('Type')
                    ->schema([
                        $checkboxlist
                    ])
            ])
            ->indicateUsing(function (array $data) use ($options): ?string {
                if (!$data['type']) {
                    return null;
                }

                foreach ($data['type'] as &$type) {
                    $type = $options[$type];
                }
                return 'Type: ' . implode(', ', $data['type'] ?? []);
            })
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['type'], fn(Builder $query, $ids) => $query
                    ->whereIn('type', $ids)
                )
            );
    }

    /**
     * Create a status filter for a resource table.
     *
     * @param string $name The name of the filter, used as the key in the query data and for the checkbox list.
     * @param string $columnName The name of the database column to filter on.
     * @param string $label The label for the filter.
     * @param array $options
     * @param ?string $default
     * @param  bool  $skipWhenTableSearch  When true, the filter is not applied while the table search bar has a value.
     * @return Filter
     */
    public static function createStatusFilter(
        string $name,
        string $columnName,
        string $label,
        array $options,
        ?string $default = '',
        bool $skipWhenTableSearch = false,
    ): Filter
    {
        $checkboxlist = CheckboxList::make($name)
            ->searchable(false)
            ->label('')
            ->options($options);

        if (!empty($default)) {
            $checkboxlist->default([$default]);
        }

        $filter = Filter::make($name)
            ->label($label)
            ->schema([
                ToggleFilter::make()
                    ->label($label)
                    ->schema([
                        $checkboxlist
                    ])
            ])
            ->indicateUsing(function (array $data, $livewire) use ($name, $label, $options, $skipWhenTableSearch): Indicator|string|null {
                if (! $data[$name]) {
                    return null;
                }

                foreach ($data[$name] as &$status) {
                    $status = $options[$status];
                }

                $indicatorLabel = $label . ': ' . implode(', ', $data[$name] ?? []);

                if (
                    $skipWhenTableSearch
                    && is_object($livewire)
                    && method_exists($livewire, 'hasTableSearch')
                    && $livewire->hasTableSearch()
                ) {
                    return Indicator::make(new HtmlString(
                        '<span class="customer-filter-status-ignored">'.e($indicatorLabel).'</span>',
                    ));
                }

                return $indicatorLabel;
            })
            ->query(function (Builder $query, array $data, $livewire) use ($name, $columnName, $skipWhenTableSearch): Builder {
                if (
                    $skipWhenTableSearch
                    && is_object($livewire)
                    && method_exists($livewire, 'hasTableSearch')
                    && $livewire->hasTableSearch()
                ) {
                    return $query;
                }

                return $query->when($data[$name], fn (Builder $query, $ids) => $query
                    ->whereIn($columnName, $ids)
                );
            });

        if (! empty($default)) {
            $filter->default([$name => [$default]]);
        }

        return $filter;
    }
}

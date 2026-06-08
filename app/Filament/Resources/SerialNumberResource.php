<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SerialNumberResource\Pages\ListSerialNumbers;
use App\Models\Customer;
use App\Models\SerialNumber;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SerialNumberResource extends Resource
{
    protected static ?string $model = SerialNumber::class;

    /**
     * Distinct from {@see SerialNumbersResource} (`serial-numbers`), which is the reporting list in the menu.
     */
    protected static ?string $slug = 'internal-serial-numbers';

    protected static ?string $modelLabel = 'serienummer';

    protected static ?string $pluralModelLabel = 'Serienummers';

    protected static ?string $recordTitleAttribute = 'serial_number';

    protected static ?int $globalSearchSort = 50;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage reporting') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canGloballySearch(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->unitRows()
            ->with(['owner', 'order.frameProduct']);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'serial_number',
            'customer_name',
            'customer_debtor_number',
            'order_number',
            'owner.first_name',
            'owner.last_name',
            'owner.email',
            'owner.phone_number',
            'owner.mobile_phone_number',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string | Htmlable
    {
        /** @var SerialNumber $record */
        $serial = $record->getSerialNumber();
        $unitName = $record->getName() ?? $record->getFrameName();

        return "{$serial} | {$unitName}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var SerialNumber $record */
        $customerName = $record->owner?->getName() ?? $record->getCustomerName();
        $debtorNumber = $record->getCustomerDebtorNumber();

        $details = [];

        if (filled($customerName)) {
            $details['Klant'] = $customerName;
        }

        if (filled($debtorNumber)) {
            $details['Debiteurnummer'] = $debtorNumber;
        }

        return $details;
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        /** @var SerialNumber $record */
        $customer = self::resolveCustomerForRecord($record);

        if ($customer !== null) {
            if (CustomerResource::canEdit($customer)) {
                return CustomerResource::getEditUrlWithTab($customer);
            }

            if (CustomerResource::canView($customer)) {
                return CustomerResource::urlWithTab(
                    CustomerResource::getUrl(parameters: [
                        'tableAction' => 'view',
                        'tableActionRecord' => $customer,
                    ]),
                );
            }
        }

        return SerialNumbersResource::getUrl('index');
    }

    /**
     * Resolve the customer for a serial number record.
     * Prefers the linked owner, falls back to a lookup by debtor number for
     * historical records where owner_id is not yet set.
     */
    private static function resolveCustomerForRecord(SerialNumber $record): ?Customer
    {
        if ($record->getOwnerId() !== null) {
            return $record->relationLoaded('owner')
                ? $record->owner
                : Customer::query()->find($record->getOwnerId());
        }

        $debtorNumber = $record->getCustomerDebtorNumber();

        if (blank($debtorNumber)) {
            return null;
        }

        return Customer::query()->where('debtor_number', $debtorNumber)->first();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_number')
                    ->label('Serienummer')
                    ->searchable(),
                TextColumn::make('owner')
                    ->label('Klant')
                    ->formatStateUsing(function (?Customer $state): string {
                        if ($state === null) {
                            return '—';
                        }

                        $name = $state->getName();

                        return filled($name) ? $name : '—';
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSerialNumbers::route('/'),
        ];
    }
}

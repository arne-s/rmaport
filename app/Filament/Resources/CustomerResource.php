<?php

namespace App\Filament\Resources;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;
    protected static ?string $breadcrumb = 'Klanten';
    protected static ?string $slug = 'customers';
    protected static ?string $modelLabel = 'klant';
    protected static ?string $pluralModelLabel = 'klanten';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage customers') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canGloballySearch(): bool
    {
        return true;
    }

    public static function hasRecordTitle(): bool
    {
        return true;
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (!$record instanceof Customer) {
            return static::getModelLabel();
        }

        return $record->getDescriptor();
    }

    /**
     * @return Builder<\App\Models\Customer>
     */
    public static function getEloquentQuery(): Builder
    {
        $visibleTypes = array_keys(CustomerType::visibleLabels());

        return parent::getEloquentQuery()
            ->with(['address', 'billingAddress', 'shippingAddress'])
            ->where('status', '!=', CustomerStatus::Initial->value)
            ->whereIn('type', $visibleTypes);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }


    public static function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn($state) => $state?->getLabel())
                    ->sortable()
                    ->searchable(),
                TextColumn::make('debtor_number')
                    ->label('Debiteurnummer')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
//                TextColumn::make('invoice_contact_person')
//                    ->label('Contactpersoon')
//                    ->state(fn (Customer $record): string => $record->getInvoiceContactPersonForOverview())
//                    ->searchable(query: function (Builder $query, string $search): Builder {
//                        $term = '%' . $search . '%';
//
//                        return $query->where(function (Builder $q) use ($term): void {
//                            $q->where('customers.first_name', 'like', $term)
//                                ->orWhere('customers.middle_name', 'like', $term)
//                                ->orWhere('customers.last_name', 'like', $term)
//                                ->orWhere('customers.name', 'like', $term)
//                                ->orWhereHas('address', fn (Builder $addr): Builder => $addr->where('name', 'like', $term))
//                                ->orWhereHas('billingAddress', fn (Builder $addr): Builder => $addr->where('name', 'like', $term));
//                        });
//                    })
//                    ->sortable(['last_name', 'middle_name', 'first_name']),

                TextColumn::make('name')
                    ->label('Klantnaam / Bedrijfsnaam')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->url(fn (Customer $record): string => static::getUrl('edit', ['record' => $record]))
                    ->color('primary'),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->state(fn (Customer $record): ?string => self::resolveCustomerEmailForOverview($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyCustomerEmailSearch($query, $search))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('customers.email', $direction);
                    }),

                TextColumn::make('phone_number')
                    ->searchable()
                    ->sortable()
                    ->label('Telefoonnummer'),

                TextColumn::make('mobile_phone_number')
                    ->searchable()
                    ->sortable()
                    ->label('Mobielnummer'),

                TextColumn::make('address.street')
                    ->searchable()
                    ->formatStateUsing(fn($record) => $record->address?->getStreetTemplate() ?? '')
                    ->sortable()
                    ->label('Straat + Huisnummer'),

                TextColumn::make('address.postcode')
                    ->searchable()
                    ->sortable()
                    ->label('Postcode'),

                TextColumn::make('address.city')
                    ->searchable()
                    ->sortable()
                    ->label('Stad'),

                TextColumn::make('dob')
                    ->label('Geboortedatum')
                    ->date('d-m-Y')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state?->getLabel())
                    ->label('Status'),


            ])
            ->defaultSort('name', 'asc')
            ->filters([
                self::createStatusFilter('type', 'type', 'Type', CustomerType::visibleLabelsInCustomerTableFilterOrder()),
                self::createStatusFilter(
                    'status',
                    'status',
                    'Status',
                    array_filter(
                        CustomerStatus::labels(),
                        fn ($key) => $key !== CustomerStatus::Initial->value,
                        ARRAY_FILTER_USE_KEY
                    ),
                    CustomerStatus::Active->value,
                    skipWhenTableSearch: true,
                ),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormSchema(function (array $filters): array {
                return [
                    $filters['type'],
                    $filters['status'],
                    Group::make([
                        SchemaActions::make([
                            Action::make('createCustomerInline')
                                ->label('Klant aanmaken')
                                ->icon('heroicon-o-user-plus')
                                ->url(fn (): string => route('filament.app.resources.customers.create'))
                                ->extraAttributes(['class' => 'customer-list-toolbar-create-btn customer-create-custom']),
                        ])
                            ->alignEnd()
                            ->verticalAlignment(VerticalAlignment::End),
                    ]),
                ];
            })
            ->deferFilters(false);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'first_name',
            'last_name',
            'email',
            'billingAddress.email',
            'shippingAddress.email',
            'phone_number',
            'mobile_phone_number',
            'debtor_number',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['billingAddress', 'shippingAddress']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Customer $record */
        return $record->getName() ?? (string)$record->getKey();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Customer $record */
        $email = self::resolveCustomerEmailForOverview($record);

        if ($email === null || $email === '') {
            return [];
        }

        return ['E-mail' => $email];
    }

    public static function resolveCustomerEmailForOverview(Customer $record): ?string
    {
        $customerEmail = trim((string) ($record->email ?? ''));
        if ($customerEmail !== '') {
            return $customerEmail;
        }

        $billingEmail = trim((string) ($record->billingAddress?->email ?? ''));
        if ($billingEmail !== '') {
            return $billingEmail;
        }

        $shippingEmail = trim((string) ($record->shippingAddress?->email ?? ''));

        return $shippingEmail !== '' ? $shippingEmail : null;
    }

    public static function applyCustomerEmailSearch(Builder $query, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('customers.email', 'like', $term)
                ->orWhereHas('billingAddress', fn (Builder $address): Builder => $address->where('email', 'like', $term))
                ->orWhereHas('shippingAddress', fn (Builder $address): Builder => $address->where('email', 'like', $term));
        });
    }

    /**
     * Bewerkpagina met actieve schema-tab ({@see EditCustomer} `persistTabInQueryString()`).
     *
     * @param  string  $tabKey  {@see Tab::key()} op het tabblad, bv. `units`.
     */
    public static function getEditUrlWithTab(Customer|string|int $record, string $tabKey = 'units'): string
    {
        return static::urlWithTab(static::getUrl('edit', ['record' => $record]), $tabKey);
    }

    /**
     * Voegt `?tab=…` (of `&tab=…`) toe voor klantschema-tabs.
     */
    public static function urlWithTab(?string $url, string $tabKey = 'units'): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $glue = str_contains($url, '?') ? '&' : '?';

        return $url.$glue.'tab='.rawurlencode($tabKey);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
            'create' => Pages\CreateCustomer::route('/create'),
            'settings' => Settings::route('/settings'),
            //'orders' => Pages\CustomerOrders::route('/{record}/orders'),
        ];
    }
}

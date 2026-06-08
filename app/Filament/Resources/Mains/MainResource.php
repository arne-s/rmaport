<?php

namespace App\Filament\Resources\Mains;

use App\Enums\CustomerAddressType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Enums\ProductType;
use App\Filament\Forms\Components\ToggleFilter;
use App\Models\Order\BaseOrder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use App\Filament\Resources\Mains\Pages\EditMain;
use App\Filament\Resources\OrderResource\Pages\ViewOrder as ViewMain;
use App\Filament\Resources\Resource;
use App\Filament\Support\SalesAuthorization;
use App\Models\Customer;
use App\Models\Order\Main;
use App\Models\Product;
use App\Models\User;
use App\Models\Setting;
use App\Support\NavigationLink;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class MainResource extends Resource
{
    public const NEW_CUSTOMER_OPTION = 'new-customer';

    protected static ?string $model = Main::class;

    protected static ?string $recordTitleAttribute = 'uid';

    protected static ?string $modelLabel = 'aanvraag';

    protected static ?string $pluralModelLabel = 'aanvragen';

    protected static ?string $slug = 'mains';

    public static function canViewAny(): bool
    {
        return SalesAuthorization::canManage();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['customer', 'billingCustomer']);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'uid',
            'reference',
            'reference_internal',
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'billingCustomer.name',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Main $record */
        return $record->getDescriptor();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Main $record */
        $billingCustomer = $record->billingCustomer;
        if ($billingCustomer?->getType()?->isBusiness()) {
            return ['Dealer' => $billingCustomer->getName() ?? ''];
        }

        return [];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        if (!static::canEdit($record) && !static::canView($record)) {
            return null;
        }

        return route('filament.app.resources.mains.view', ['record' => $record->getKey()]);
    }

    public static function isNewCustomerSelection(mixed $value): bool
    {
        return is_string($value) && $value === self::NEW_CUSTOMER_OPTION;
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public static function prependNewCustomerOption(array $options): array
    {
        return [self::NEW_CUSTOMER_OPTION => 'Nieuwe klant'] + $options;
    }

    public static function hasExistingCustomerOrDealerSelected(Get $get): bool
    {
        $value = $get('customer_or_dealer');

        return is_string($value) && filled($value) && ! self::isNewCustomerSelection($value);
    }

    public static function hasCustomerPartySelected(Get $get): bool
    {
        return filled($get('customer_or_dealer'));
    }

    public static function isNewCustomerBillingSelection(mixed $value): bool
    {
        return self::isNewCustomerSelection($value);
    }

    public static function newCustomerPreviewLabel(Get $get): string
    {
        $name = trim((string) ($get('new_customer_name') ?? ''));

        return $name !== '' ? "(nieuwe klant): {$name}" : '(nieuwe klant)';
    }

    /**
     * @return array<string|int, string>
     */
    private static function getBillingCustomerOptionsForCreateMain(?string $search, Get $get): array
    {
        $query = static::queryBillingCustomersForNewMainSelect();

        if (is_string($search) && $search !== '') {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
            );
        }

        $existing = $query
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $dealer): array => [
                $dealer->id => (string) $dealer->getName(),
            ])
            ->all();

        if (self::isNewCustomerSelection($get('customer_or_dealer'))) {
            return [self::NEW_CUSTOMER_OPTION => self::newCustomerPreviewLabel($get)] + $existing;
        }

        return $existing;
    }

    private static function syncCreateMainDeliveryAddressTypeForNewCustomer(Set $set, Get $get): void
    {
        $billing = $get('billing_customer_id');

        if (self::isNewCustomerBillingSelection($billing)) {
            $set('delivery_address_type', 'customer');

            return;
        }

        if (is_numeric($billing) && (int) $billing !== 0) {
            $set(
                'delivery_address_type',
                self::resolveCreateMainDefaultDeliveryAddressType(
                    null,
                    (int) $billing,
                    is_string($get('subtype')) ? $get('subtype') : null,
                ),
            );

            return;
        }

        $set('delivery_address_type', 'customer');
    }

    /**
     * @return array<string, string>
     */
    private static function getDeliveryAddressTypeOptionsForCreateMain(Get $get): array
    {
        if (self::isNewCustomerSelection($get('customer_or_dealer'))) {
            $options = [
                'customer' => self::newCustomerPreviewLabel($get),
            ];

            $billingCustomerId = $get('billing_customer_id');
            if (
                is_numeric($billingCustomerId)
                && (int) $billingCustomerId !== 0
                && ! self::isNewCustomerBillingSelection($billingCustomerId)
            ) {
                $billing = Customer::query()->find((int) $billingCustomerId);
                if ($billing !== null) {
                    $typeLabel = match ($billing->getType()) {
                        CustomerType::Dealer => 'Dealer',
                        CustomerType::B2B => 'B2B',
                        CustomerType::B2C => 'Klant',
                        CustomerType::UniekSporten => 'UniekSporten',
                        default => 'Factuurklant',
                    };
                    $options['dealer'] = $typeLabel . ': ' . $billing->getName();
                }
            }

            return $options;
        }

        $options = [];
        $billingCustomerId = $get('billing_customer_id');
        $customerId = $get('customer_id');

        if ($billingCustomerId && (int) $billingCustomerId !== (int) $customerId) {
            $billing = Customer::query()->find((int) $billingCustomerId);
            if ($billing !== null) {
                $typeLabel = match ($billing->getType()) {
                    CustomerType::Dealer => 'Dealer',
                    CustomerType::B2B => 'B2B',
                    CustomerType::B2C => 'Klant',
                    CustomerType::UniekSporten => 'UniekSporten',
                    default => 'Factuurklant',
                };
                $options['dealer'] = $typeLabel . ': ' . $billing->getName();
            }
        }

        if ($customerId) {
            $customer = Customer::query()->find($customerId);
            if ($customer) {
                $prefix = $customer->getType() === CustomerType::Dealer ? 'Dealer' : 'Klant';
                $options['customer'] = $prefix . ': ' . $customer->getName();
            }
        }

        return $options;
    }

    /**
     * Create an active B2C customer from the create-main modal inline fields.
     */
    public static function createB2CCustomerFromCreateMainForm(array $data): Customer
    {
        $name = trim((string) ($data['new_customer_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'new_customer_name' => 'Voor- en Achternaam is verplicht.',
            ]);
        }

        $attributes = [
            'type' => CustomerType::B2C->value,
            'status' => CustomerStatus::Active->value,
            'name' => $name,
            'comment' => filled($data['new_customer_comment'] ?? null)
                ? trim((string) $data['new_customer_comment'])
                : null,
        ];

        $conditionCode = Setting::get('exact_payment_condition_by_type.' . CustomerType::B2C->value);
        if (is_string($conditionCode) && $conditionCode !== '') {
            $attributes['exact_payment_condition'] = $conditionCode;
        }

        return Customer::query()->create($attributes);
    }

    /**
     * Default delivery-address choice when creating a main.
     * Separate dealer billing → deliver to dealer; Part with any separate billing → dealer; otherwise end customer.
     */
    private static function syncCreateMainDeliveryAddressType(Set $set, ?int $customerId, ?int $billingCustomerId, ?string $subtype = null): void
    {
        $set(
            'delivery_address_type',
            self::resolveCreateMainDefaultDeliveryAddressType($customerId, $billingCustomerId, $subtype),
        );
    }

    private static function resolveCreateMainDefaultDeliveryAddressType(
        ?int $customerId,
        ?int $billingCustomerId,
        ?string $subtype = null,
    ): string {
        $resolvedBillingId = ($billingCustomerId !== null && $billingCustomerId !== 0)
            ? $billingCustomerId
            : (($customerId !== null && $customerId !== 0) ? $customerId : null);

        if ($resolvedBillingId === null || $resolvedBillingId === 0) {
            return 'dealer';
        }

        $hasSeparateBilling = $customerId !== null && $customerId !== 0
            && $billingCustomerId !== null && $billingCustomerId !== 0
            && (int) $billingCustomerId !== (int) $customerId;

        if ($hasSeparateBilling) {
            if ($subtype === OrderSubtype::Part->value) {
                return 'dealer';
            }

            $billing = Customer::query()->find((int) $billingCustomerId);
            if ($billing?->getType() === CustomerType::Dealer) {
                return 'dealer';
            }
        }

        return ($customerId !== null && $customerId !== 0) ? 'customer' : 'dealer';
    }

    /**
     * UniekSporten customers only appear when the request subtype is Unit.
     */
    private static function applyCreateMainCustomerSubtypeFilter(Builder $query, ?string $subtype): void
    {
        if (($subtype ?? '') !== OrderSubtype::Unit->value) {
            $query->where('type', '!=', CustomerType::UniekSporten->value);
        }
    }

    /**
     * Base query for visible non-dealer customers on the new-main form (subtype filter applied).
     */
    private static function baseNonDealerCustomerQueryForCreateMain(?string $subtype): Builder
    {
        $query = Customer::query()
            ->active()
            ->with(['shippingAddress'])
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where('type', '!=', CustomerType::Dealer->value);
        static::applyCreateMainCustomerSubtypeFilter($query, $subtype);

        return $query;
    }

    /**
     * Flat customer/dealer options for the create-main select (`customer-{id}` / `dealer-{id}` value => display name).
     *
     * @return array<string, string>
     */
    private static function buildCreateMainCustomerOrDealerOptions(?string $subtype, ?string $search = null): array
    {
        $query = static::baseNonDealerCustomerQueryForCreateMain($subtype);

        if (is_string($search) && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('shippingAddress', fn ($a) => $a->where('location_name', 'like', "%{$search}%"));
            });
        }

        $limit = $search !== null && $search !== '' ? 100 : 50;

        $customers = $query
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->unique('email')
            ->take(50);

        $options = [];
        foreach ($customers as $customer) {
            $type = $customer->getType();
            if ($type === null || ! $type->isVisible() || $type === CustomerType::Dealer) {
                continue;
            }
            $options['customer-' . $customer->id] = $customer->getName() ?? '';

            if ($type->isBusiness()) {
                $shippingLocationName = $customer->shippingAddress?->getLocationName();
                if (filled($shippingLocationName)) {
                    $options['customer-' . $customer->id . '-shipping'] = $shippingLocationName . ' (locatie)';
                }
            }
        }

        $dealersQuery = Customer::query()
            ->active()
            ->with(['shippingAddress'])
            ->where('type', CustomerType::Dealer->value);

        if (is_string($search) && $search !== '') {
            $dealersQuery->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereHas('shippingAddress', fn ($a) => $a->where('location_name', 'like', "%{$search}%"));
            });
        }

        foreach ($dealersQuery->orderBy('name')->limit(50)->get() as $dealer) {
            $options['dealer-' . $dealer->id] = $dealer->getName();

            $shippingLocationName = $dealer->shippingAddress?->getLocationName();
            if (filled($shippingLocationName)) {
                $options['dealer-' . $dealer->id . '-shipping'] = $shippingLocationName . ' (locatie)';
            }
        }

        return self::prependNewCustomerOption($options);
    }

    /**
     * Customers allowed in the optional Factuurgegevens field when creating a main: all active types except RD Mobility.
     */
    public static function queryBillingCustomersForNewMainSelect(): Builder
    {
        return Customer::query()
            ->active()
            ->where('type', '!=', CustomerType::AV->value);
    }

    /**
     * @return list<\Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    public static function createMainFormSubtypeFields(bool $hideRequestTypeSelect): array
    {
        if ($hideRequestTypeSelect) {
            return [
                Select::make('subtype')
                    ->selectablePlaceholder(false)
                    ->label('Type aanvraag')
                    ->options(OrderSubtype::labels())
                    ->default(OrderSubtype::Unit->value)
                    ->disabled()
                    ->dehydrated()
                    ->columnSpanFull()
                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap']),

            ];
        }

        return [
            Select::make('subtype')
                ->selectablePlaceholder(false)
                ->label('Type aanvraag')
                ->options(function (Get $get): array {
                    $options = OrderSubtype::labels();

                    if ((string) $get('mode') === 'order') {
                        unset($options[OrderSubtype::Unit->value]);
                    }

                    return $options;
                })
                ->default(function (Get $get): string {
                    return (string) $get('mode') === 'order'
                        ? OrderSubtype::Part->value
                        : OrderSubtype::Unit->value;
                })
                ->columnSpanFull()
                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                    if (is_string($state) && $state !== OrderSubtype::Unit->value) {
                        $cord = $get('customer_or_dealer');
                        if (is_string($cord) && str_starts_with($cord, 'customer-')) {
                            $customerId = (int) str_replace('customer-', '', $cord);
                            $customer = Customer::query()->find($customerId);
                            if ($customer !== null && $customer->getType() === CustomerType::UniekSporten) {
                                $set('customer_or_dealer', null);
                                $set('customer_id', null);
                                $set('billing_customer_id', null);
                            }
                        }
                    }

                    $customerId = $get('customer_id');
                    $billingCustomerId = $get('billing_customer_id');
                    static::syncCreateMainDeliveryAddressType(
                        $set,
                        is_numeric($customerId) ? (int) $customerId : null,
                        is_numeric($billingCustomerId) && (int) $billingCustomerId !== 0
                            ? (int) $billingCustomerId
                            : null,
                        is_string($state) ? $state : null,
                    );
                })
                ->live(),
        ];
    }

    public static function getCreateFormSchema(bool $hideRequestTypeSelect = false): array
    {
        return [
            Grid::make(2)
                ->extraAttributes(['class' => 'custom-form-design main-modal'])
                ->schema([
                    ...static::createMainFormSubtypeFields($hideRequestTypeSelect),

                    Select::make('customer_or_dealer')
                        ->columnSpanFull()
                        ->required()
                        ->label('Klant')
                        ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                        ->validationMessages([
                            'required' => 'Selecteer een klant.',
                        ])
                        ->searchable()
                        ->options(fn (Get $get): array => self::getCustomerOrDealerOptions(is_string($get('subtype')) ? $get('subtype') : null))
                        ->getSearchResultsUsing(function (string $search, Get $get) {
                            $subtype = is_string($get('subtype')) ? $get('subtype') : null;

                            return self::buildCreateMainCustomerOrDealerOptions($subtype, $search);
                        })
                        ->getOptionLabelUsing(function ($value) {
                            if (!is_string($value)) {
                                return '';
                            }

                            if (self::isNewCustomerSelection($value)) {
                                return 'Nieuwe klant';
                            }

                            if (preg_match('/^customer-(\d+)-shipping$/', $value, $matches)) {
                                $customer = Customer::query()->with('shippingAddress')->find((int) $matches[1]);
                                if ($customer === null) {
                                    return '';
                                }
                                $locationName = $customer->shippingAddress?->getLocationName();

                                return $locationName ? $locationName . ' (locatie)' : ($customer->getName() ?? '');
                            }

                            if (str_starts_with($value, 'customer-')) {
                                $customer = Customer::query()->find((int) str_replace('customer-', '', $value));

                                return $customer ? 'Customer: ' . $customer->getName() : '';
                            }

                            if (preg_match('/^dealer-(\d+)-shipping$/', $value, $matches)) {
                                $dealer = Customer::query()->with('shippingAddress')->find((int) $matches[1]);
                                if ($dealer === null) {
                                    return '';
                                }
                                $locationName = $dealer->shippingAddress?->getLocationName();

                                return $locationName ? $locationName . ' (locatie)' : ($dealer->getName() ?? '');
                            }

                            if (str_starts_with($value, 'dealer-')) {
                                $dealer = Customer::query()->find((int) str_replace('dealer-', '', $value));

                                return $dealer ? 'Dealer: ' . $dealer->getName() . ($dealer->email ? ' (' . $dealer->email . ')' : '') : '';
                            }

                            return '';
                        })
                        ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                            if (! is_string($state)) {
                                $set('customer_id', null);
                                $set('billing_customer_id', null);
                                $set('customer_address_type', CustomerAddressType::Billing->value);

                                return;
                            }

                            if (self::isNewCustomerSelection($state)) {
                                $set('customer_id', null);
                                $set('billing_customer_id', self::NEW_CUSTOMER_OPTION);
                                $set('customer_address_type', CustomerAddressType::Billing->value);
                                $set('delivery_address_type', 'customer');
                                $set('new_customer_type', CustomerType::B2C->value);

                                return;
                            }

                            if (preg_match('/^customer-(\d+)-shipping$/', $state, $matches)) {
                                $customerId = (int) $matches[1];
                                $set('customer_id', $customerId);
                                $set('billing_customer_id', $customerId);
                                $set('customer_address_type', CustomerAddressType::Shipping->value);
                                static::syncCreateMainDeliveryAddressType(
                                    $set,
                                    $customerId,
                                    $customerId,
                                    is_string($get('subtype')) ? $get('subtype') : null,
                                );

                                return;
                            }

                            if (str_starts_with($state, 'customer-')) {
                                $customerId = (int) str_replace('customer-', '', $state);
                                $set('customer_id', $customerId);
                                $set('billing_customer_id', $customerId);
                                $set('customer_address_type', CustomerAddressType::Billing->value);
                                static::syncCreateMainDeliveryAddressType(
                                    $set,
                                    $customerId,
                                    $customerId,
                                    is_string($get('subtype')) ? $get('subtype') : null,
                                );

                                return;
                            }

                            if (preg_match('/^dealer-(\d+)-shipping$/', $state, $matches)) {
                                $dealerCustomerId = (int) $matches[1];
                                $set('customer_id', $dealerCustomerId);
                                $set('billing_customer_id', $dealerCustomerId);
                                $set('customer_address_type', CustomerAddressType::Shipping->value);
                                static::syncCreateMainDeliveryAddressType(
                                    $set,
                                    $dealerCustomerId,
                                    $dealerCustomerId,
                                    is_string($get('subtype')) ? $get('subtype') : null,
                                );

                                return;
                            }

                            if (str_starts_with($state, 'dealer-')) {
                                $dealerCustomerId = (int) str_replace('dealer-', '', $state);
                                $set('customer_id', $dealerCustomerId);
                                $set('billing_customer_id', $dealerCustomerId);
                                $set('customer_address_type', CustomerAddressType::Billing->value);
                                static::syncCreateMainDeliveryAddressType(
                                    $set,
                                    $dealerCustomerId,
                                    $dealerCustomerId,
                                    is_string($get('subtype')) ? $get('subtype') : null,
                                );
                            }
                        })
                        ->live(),

                    Html::make('<hr>')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::isNewCustomerSelection($get('customer_or_dealer'))),

                    Select::make('new_customer_type')
                        ->label('Type')
                        ->options([
                            CustomerType::B2C->value => 'Particulier',
                        ])
                        ->default(CustomerType::B2C->value)
                        ->selectablePlaceholder(false)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::isNewCustomerSelection($get('customer_or_dealer'))),

                    TextInput::make('new_customer_name')
                        ->label('Voor- en Achternaam')
                        ->columnSpanFull()
                        ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                        ->required(fn (Get $get): bool => self::isNewCustomerSelection($get('customer_or_dealer')))
                        ->validationMessages([
                            'required' => 'Voor- en Achternaam is verplicht.',
                        ])
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => self::isNewCustomerSelection($get('customer_or_dealer'))),

                    Textarea::make('new_customer_comment')
                        ->label('Notities')
                        ->columnSpanFull()
                        ->rows(4)
                        ->maxLength(65535)
                        ->extraInputAttributes([
                            'style' => 'border: 1px solid #adadad; border-radius: 3px;',
                        ])
                        ->visible(fn (Get $get): bool => self::isNewCustomerSelection($get('customer_or_dealer'))),

                    Html::make('<hr>')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::isNewCustomerSelection($get('customer_or_dealer'))),

                    Select::make('billing_customer_id')
                        ->label('Factuurgegevens')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::hasCustomerPartySelected($get))
                        ->searchable()
                        ->placeholder(fn (Get $get): ?string => self::isNewCustomerSelection($get('customer_or_dealer'))
                            ? null
                            : 'Geen afwijkende factuurgegevens')
                        ->nullable(fn (Get $get): bool => ! self::isNewCustomerSelection($get('customer_or_dealer')))
                        ->default(fn (Get $get): ?string => self::isNewCustomerSelection($get('customer_or_dealer'))
                            ? self::NEW_CUSTOMER_OPTION
                            : null)
                        ->selectablePlaceholder(fn (Get $get): bool => ! self::isNewCustomerSelection($get('customer_or_dealer')))
                        ->options(fn (Get $get): array => self::getBillingCustomerOptionsForCreateMain(null, $get))
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => self::getBillingCustomerOptionsForCreateMain($search, $get))
                        ->getOptionLabelUsing(function ($value): string {
                            if (self::isNewCustomerBillingSelection($value)) {
                                return '(nieuwe klant)';
                            }

                            if (! is_numeric($value)) {
                                return '';
                            }

                            $customer = Customer::query()->find((int) $value);

                            return $customer ? (string) $customer->getName() : '';
                        })
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            if (self::isNewCustomerSelection($get('customer_or_dealer'))) {
                                self::syncCreateMainDeliveryAddressTypeForNewCustomer($set, $get);

                                return;
                            }

                            $customerId = $get('customer_id');
                            $dealerCustomerId = $get('billing_customer_id');
                            static::syncCreateMainDeliveryAddressType(
                                $set,
                                is_numeric($customerId) ? (int) $customerId : null,
                                is_numeric($dealerCustomerId) ? (int) $dealerCustomerId : null,
                                is_string($get('subtype')) ? $get('subtype') : null,
                            );
                        })
                        ->live(),

                    Select::make('delivery_address_type')
                        ->label('Levergegevens')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::hasCustomerPartySelected($get))
                        ->required(fn (Get $get): bool => self::hasCustomerPartySelected($get))
                        ->validationMessages([
                            'required' => 'Selecteer levergegevens.',
                        ])
                        ->options(fn (Get $get): array => self::getDeliveryAddressTypeOptionsForCreateMain($get))
                        ->default(function (Get $get): string {
                            if (self::isNewCustomerSelection($get('customer_or_dealer'))) {
                                return 'customer';
                            }

                            $customerRaw = $get('customer_id');
                            $billingRaw = $get('billing_customer_id');
                            $customerId = (filled($customerRaw) && (int) $customerRaw !== 0)
                                ? (int) $customerRaw
                                : null;
                            $billingId = (filled($billingRaw) && (int) $billingRaw !== 0)
                                ? (int) $billingRaw
                                : null;

                            return self::resolveCreateMainDefaultDeliveryAddressType(
                                $customerId,
                                $billingId,
                                is_string($get('subtype')) ? $get('subtype') : null,
                            );
                        })
                        ->selectablePlaceholder(false)
                        ->live(),

                    Hidden::make('customer_id')
                        ->dehydrated(),

                    Hidden::make('customer_address_type')
                        ->dehydrated(),
                ]),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema(static::getCreateFormSchema());
    }

    protected static function getCustomerOrDealerOptions(?string $subtype = null): array
    {
        return self::buildCreateMainCustomerOrDealerOptions($subtype, null);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->headerActions([])
            ->columns([
                TextColumn::make('uid')
                    ->label('Aanvraagnummer')
                    ->formatStateUsing(fn (Main $record): \Illuminate\Contracts\Support\Htmlable|string => NavigationLink::main(
                        $record->getId(),
                        $record->getUid() ?? '-',
                    ))
                    ->searchable(['uid'])
                    ->sortable(['uid'])
                    ->width('5%'),
                TextColumn::make('subtype')
                    ->label('Type')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn($state) => $state?->getLabel() ?? '-')
                    ->disabledClick(),

                TextColumn::make('customer_id')
                    ->label('Klant')
                    ->formatStateUsing(fn(Main $record): string => $record->getCustomerAddressDisplayName() ?? '')
                    ->url(fn(Main $record): string => $record->customer_id
                        ? route('filament.app.resources.customers.edit', ['record' => $record->customer_id])
                        : '')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('customer', function (Builder $q) use ($search): Builder {
                            return $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orWhereHas('shippingAddress', fn (Builder $a) => $a->where('location_name', 'like', "%{$search}%"));
                        });
                    }),

                TextColumn::make('order_status')
                    ->label('Aanvraag-status')
                    ->formatStateUsing(function ($state): string {
                        $status = $state instanceof OrderStatus
                            ? $state
                            : OrderStatus::tryFrom((string)$state);

                        if ($status === null) {
                            return (string)$state;
                        }

                        return OrderStatus::formatWithMainIndexAndSubLabel($status);
                    })
                    ->sortable(),

                TextColumn::make('billingCustomer.name')
                    ->label('Dealer')
                    ->state(fn(Main $record): string => $record->billingCustomer?->getType()?->isBusiness()
                        ? ($record->billingCustomer->getName() ?? '')
                        : '')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('billingCustomer', function (Builder $q) use ($search): Builder {
                            return $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('advisor_id')
                    ->label('Adviseur')
                    ->formatStateUsing(fn($record) => $record->advisor ? trim($record->advisor->first_name . ' ' . $record->advisor->last_name) : '')
                    ->sortable(['advisor_id'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('advisor', function (Builder $q) use ($search): Builder {
                            return $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('created_at')
                    ->label('Datum (aangemaakt)')
                    ->date('j M Y')
                    ->sortable(['created_at'])
                    ->searchable(['created_at']),
            ])
            ->defaultSort(fn(Builder $query): Builder => $query
                ->orderByRaw('CAST(uid AS UNSIGNED) DESC')
                ->orderByDesc('id')
            )
            ->deferFilters(false)
            ->filters([
                self::getDateFilter(),
                Resource::getDealerFilter(),
                self::getAdvisorFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([])
            ->extraAttributes(['class' => 'searchAlignLeft']);
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewMain::route('/{record}'),
            'edit' => EditMain::route('/{record}/edit'),
        ];
    }

    protected static function getAdvisorFilter(): Filter
    {
        return Filter::make('advisor_id')
            ->label('Adviseur')
            ->indicateUsing(function (array $data): ?string {
                if (empty($data['advisor_id'])) {
                    return null;
                }
                $users = User::query()
                    ->whereIn('id', $data['advisor_id'])
                    ->get();
                $list = $users->map(fn(User $c) => $c->getName())->values();
                if ($list->count() > 1) {
                    $str = $list->first() . ' (+' . ($list->count() - 1) . ')';
                } else {
                    $str = $list->join(', ');
                }

                return 'Adviseur: ' . $str;
            })
            ->schema([
                ToggleFilter::make()
                    ->label('Adviseur')
                    ->schema([
                        CheckboxList::make('advisor_id')
                            ->label('')
                            ->options(fn (): array => User::query()
                                ->advisors()
                                ->orderBy('first_name')
                                ->get()
                                ->mapWithKeys(fn (User $c): array => [
                                    $c->id => $c->getName(),
                                ])
                                ->all()),
                    ]),
            ])
            ->query(fn(Builder $query, array $data): Builder => $query
                ->when($data['advisor_id'] ?? null, fn(Builder $query, array $ids): Builder => $query->whereIn('advisor_id', $ids))
            );
    }
}

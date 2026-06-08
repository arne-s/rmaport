<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Concerns\DispatchesExactSyncToastPolling;
use App\Filament\Resources\CustomerResource\Widgets\CompanyDocumentsWidget;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Filament\Resources\CustomerResource\Widgets\CustomerNotesWidget;
use App\Filament\Resources\CustomerResource\Widgets\CustomerUnitsWidget;
use App\Filament\Resources\OrderResource\Actions\SendCustomerEmailAction;
use App\Jobs\SyncCustomerToExactJob;
use App\Models\Address;
use App\Models\Country;
use App\Models\ExactPaymentCondition;
use App\Models\ExactVATCode;
use App\Rules\ValidDutchVatNumber;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class EditCustomer extends EditRecord
{
    use DispatchesExactSyncToastPolling;
    use PostcodeValidatorTrait;

    protected static string $resource = CustomerResource::class;

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $record = $this->record;
        if (! $record instanceof Customer || ! $record->exists) {
            return parent::getBreadcrumbs();
        }

        return [
            CustomerResource::getUrl('index') => 'Klanten',
            CustomerResource::getUrl('edit', ['record' => $record]) => $this->getCustomerHeadingName(),
        ];
    }

    protected function resolveRecord(int|string $key): Customer
    {
        return Customer::findOrFail($key);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (($data['status'] ?? null) === CustomerStatus::Initial->value) {
            $data['status'] = CustomerStatus::Active->value;
        }

        if (self::isDealerType($data['type'] ?? null)) {
            $data['delivery_address_type'] = 'custom';
        }

        if (self::isBusinessType($data['type'] ?? null)) {
            $billing = is_array($data['billingAddress'] ?? null) ? $data['billingAddress'] : [];
            $customerEmail = trim((string) ($data['email'] ?? ''));

            if (trim((string) ($billing['email'] ?? '')) === '' && $customerEmail !== '') {
                $billing['email'] = $customerEmail;
            }

            if (! array_key_exists('newsletter_subscribed', $billing) && array_key_exists('newsletter_subscribed', $data)) {
                $billing['newsletter_subscribed'] = $data['newsletter_subscribed'];
            }

            $data['billingAddress'] = $billing;
        } else {
            $data = self::hydrateBillingAddressFormFromLegacyAddress($data);
        }

        $data = self::applyShippingLocationNameFromCustomerName($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (self::isDealerType($data['type'] ?? null)) {
            $data['delivery_address_type'] = 'custom';
        }

        if (self::isBusinessType($data['type'] ?? null)) {
            $billing = is_array($data['billingAddress'] ?? null) ? $data['billingAddress'] : [];
            $billingEmail = trim((string) ($billing['email'] ?? ''));

            if ($billingEmail !== '') {
                $data['email'] = $billingEmail;
            }

            unset($data['newsletter_subscribed']);
        }

        $data = self::applyShippingLocationNameFromCustomerName($data);

        return $data;
    }

    private static function isBusinessType(?string $type): bool
    {
        if ($type === null) {
            return false;
        }

        $case = CustomerType::tryFrom($type);

        return $case !== null && $case->isBusiness();
    }

    private static function isDealerType(?string $type): bool
    {
        return CustomerType::tryFrom($type) === CustomerType::Dealer;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function applyShippingLocationNameFromCustomerName(array $data): array
    {
        $customerName = trim((string) ($data['name'] ?? ''));
        if ($customerName === '') {
            return $data;
        }

        $shipping = is_array($data['shippingAddress'] ?? null) ? $data['shippingAddress'] : [];
        $existingLocationName = trim((string) ($shipping['location_name'] ?? ''));
        if ($existingLocationName !== '') {
            return $data;
        }

        $shipping['location_name'] = $customerName;
        $data['shippingAddress'] = $shipping;

        return $data;
    }

    private static function syncShippingLocationNameFromCustomerName(Get $get, Set $set): void
    {
        $customerName = trim((string) ($get('name') ?? ''));
        if ($customerName === '') {
            return;
        }

        $locationName = trim((string) ($get('shippingAddress.location_name') ?? ''));
        if ($locationName !== '') {
            return;
        }

        $set('shippingAddress.location_name', $customerName);
    }

    /**
     * Invoice/delivery newsletter (Mailchimp dealer segments): shown for eligible business types; RD excluded.
     */
    private static function usesNewsletterDealerCustomerType(?string $type): bool
    {
        return CustomerType::tryFrom($type)?->usesNewsletterDealerSegments() ?? false;
    }

    private static function isNewsletterFormChoiceLocked(?string $status): bool
    {
        return CustomerStatus::tryFrom($status) !== CustomerStatus::Active;
    }

    private static function syncShippingAddress(Get $get, Set $set, mixed $record = null): void
    {
        $deliveryType = $get('delivery_address_type');

        if ($deliveryType === 'contact') {
            $sourcePrefix = 'billingAddress';

            $set('shippingAddress.postcode', $get("{$sourcePrefix}.postcode") ?? self::shippingSourcePostcode($record, $sourcePrefix));
            $set('shippingAddress.house_number', $get("{$sourcePrefix}.house_number") ?? self::shippingSourceHouseNumber($record, $sourcePrefix));
            $set('shippingAddress.house_number_addition', $get("{$sourcePrefix}.house_number_addition") ?? self::shippingSourceHouseAddition($record, $sourcePrefix));
            $set('shippingAddress.country_id', $get("{$sourcePrefix}.country_id") ?? self::shippingSourceCountryId($record, $sourcePrefix));
            $set('shippingAddress.street', $get("{$sourcePrefix}.street") ?? self::shippingSourceStreet($record, $sourcePrefix));
            $set('shippingAddress.city', $get("{$sourcePrefix}.city") ?? self::shippingSourceCity($record, $sourcePrefix));
        } elseif ($deliveryType === 'custom') {
            $set('shippingAddress.postcode', null);
            $set('shippingAddress.house_number', null);
            $set('shippingAddress.house_number_addition', null);
            $set('shippingAddress.country_id', Country::NL_ID);
            $set('shippingAddress.street', null);
            $set('shippingAddress.city', null);
        }
    }

    private static function shippingSourcePostcode(?Customer $record, string $sourcePrefix): ?string
    {
        return self::shippingSourceAddress($record, $sourcePrefix)?->postcode;
    }

    private static function shippingSourceHouseNumber(?Customer $record, string $sourcePrefix): ?string
    {
        return self::shippingSourceAddress($record, $sourcePrefix)?->house_number;
    }

    private static function shippingSourceHouseAddition(?Customer $record, string $sourcePrefix): ?string
    {
        return self::shippingSourceAddress($record, $sourcePrefix)?->house_number_addition;
    }

    private static function shippingSourceCountryId(?Customer $record, string $sourcePrefix): ?int
    {
        return self::shippingSourceAddress($record, $sourcePrefix)?->country_id;
    }

    private static function shippingSourceStreet(?Customer $record, string $sourcePrefix): ?string
    {
        return self::shippingSourceAddress($record, $sourcePrefix)?->street;
    }

    private static function shippingSourceCity(?Customer $record, string $sourcePrefix): ?string
    {
        return self::shippingSourceAddress($record, $sourcePrefix)?->city;
    }

    private static function shippingSourceAddress(?Customer $record, string $sourcePrefix): ?Address
    {
        if ($record === null) {
            return null;
        }

        return match ($sourcePrefix) {
            'billingAddress' => $record->billingAddress ?? $record->address,
            'address' => $record->address,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function hydrateBillingAddressFormFromLegacyAddress(array $data): array
    {
        $billing = is_array($data['billingAddress'] ?? null) ? $data['billingAddress'] : [];
        if (Customer::addressFormArrayHasContent($billing)) {
            return $data;
        }

        $legacy = is_array($data['address'] ?? null) ? $data['address'] : [];
        if (Customer::addressFormArrayHasContent($legacy)) {
            $data['billingAddress'] = $legacy;
        }

        return $data;
    }

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> Used by mail modal document upload. */
    public array $documentFiles = [];


    public function getHeading(): string
    {
        return sprintf('Klantnaam: "%s"', $this->getCustomerHeadingName());
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getCustomerHeadingName();
    }

    /**
     * Primary display name only (without type / debiteurnummer), for when a short label is needed.
     */
    public function getCustomerHeadingName(): string
    {
        $record = $this->record;
        if (! $record instanceof Customer) {
            return '-';
        }

        $name = trim((string) ($record->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $record->getDescriptor();
    }

    /**
     * Name, type label, debiteurnummer for the custom header only ({@see resources/views/filament/components/back-to-overview-with-heading.blade.php}).
     */
    public function getCustomerHeadingDisplayName(): string
    {
        $record = $this->record;
        if (! $record instanceof Customer) {
            return '-';
        }

        $segments = [$this->getCustomerHeadingName()];

        $type = $record->getType();
        if ($type !== null) {
            $label = $type->getLabel();
            if (is_string($label) && $label !== '') {
                $segments[] = $label;
            }
        }

        $debtor = trim((string) ($record->debtor_number ?? ''));
        if ($debtor !== '') {
            $segments[] = $debtor;
        }

        return implode(' | ', $segments);
    }

    protected function afterSave(): void
    {
        $this->record->ensureBillingAndShippingAddressLinks();
        $this->record->refresh();

        if (!config('exact.enabled')) {
            Notification::make()
                ->title('Exact-koppeling uitgeschakeld')
                ->warning()
                ->send();

            return;
        }

        if (! $this->record->shouldPushCustomerToExact()) {
            return;
        }

        SyncCustomerToExactJob::dispatch($this->record->id, auth()->id());
        $this->requestExactSyncToastPolling();
    }

    protected function getHeaderActions(): array
    {
        return [
        ];
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([

                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Klanten-overzicht',
                        'url' => route('filament.app.resources.customers.index'),
                    ]),

                SendCustomerEmailAction::make('send_customer_email')
                    ->extraAttributes(['class' => 'customerMailAction']),

                Tabs::make('Tabs')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Klantgegevens')
                            ->key('klantgegevens')
                            ->schema([

                                Section::make('')
                                    ->extraAttributes(['class' => 'customerSection'])
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->visible(fn(Get $get) => !self::isBusinessType($get('type')))
                                                    ->schema([
                                                        Section::make('Klantgegevens')
                                                            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens'])
                                                            ->schema([
                                                                Select::make('type')
                                                                    ->columnSpan(3)
                                                                    ->label('Type')
                                                                    ->inlineLabel()
                                                                    ->extraAttributes(['class' => 'companySection-statusrequiredSelect'])
                                                                    ->options(CustomerType::visibleLabels())
                                                                    ->disabled()
                                                                    ->dehydrated()
                                                                    ->visible(fn(Get $get) => CustomerType::tryFrom($get('type'))?->isVisible() ?? false),
                                                                Select::make('salutation')
                                                                    ->label('Aanhef')
                                                                    ->placeholder('Selecteer')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->options([
                                                                        'Dhr.' => 'Dhr.',
                                                                        'Mevr.' => 'Mevr.',
                                                                    ]),
                                                                TextInput::make('name')
                                                                    ->columnSpan(3)
                                                                    ->label('Voor- en Achternaam')
                                                                    ->required()
                                                                    ->inlineLabel()
                                                                    ->live(onBlur: true)
                                                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                                                        self::syncShippingLocationNameFromCustomerName($get, $set);
                                                                    })
                                                                    ->maxLength(255),
                                                                DatePicker::make('dob')
                                                                    ->label('Geboortedatum')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->native(true)
                                                                    ->nullable()
                                                                    ->maxDate(now())
                                                                    ->rule('before_or_equal:today'),
                                                                TextInput::make('email')
                                                                    ->label('E-mail')
                                                                    ->email()
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->autocomplete(),
                                                                Checkbox::make('newsletter_subscribed')
                                                                    ->columnSpan(3)
                                                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap checkbox-wrap'])
                                                                    ->label('Inschrijven nieuwsbrief')
                                                                    ->inlineLabel()
                                                                    ->default(true)
                                                                    ->disabled(fn(Get $get) => self::isNewsletterFormChoiceLocked($get('status')))
                                                                    ->dehydrated(true),
                                                                TextInput::make('phone_number')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->label('Telefoonnummer')
                                                                    ->maxLength(255),
                                                                TextInput::make('mobile_phone_number')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->label('Mobiel nummer')
                                                                    ->maxLength(255),
                                                                Select::make('status')
                                                                    ->columnSpan(3)
                                                                    ->label('Status')
                                                                    ->inlineLabel()
                                                                    ->extraAttributes(['class' => 'companySection-statusrequiredSelect'])
                                                                    ->options(CustomerStatus::labelsForEditForm())
                                                                    ->default(CustomerStatus::Active)
                                                                    ->live()
                                                                    ->required(),
                                                                TextInput::make('reason_inactive')
                                                                    ->columnSpan(3)
                                                                    ->label('Reden inactief')
                                                                    ->required()
                                                                    ->inlineLabel()
                                                                    ->visible(fn(Get $get) => $get('status') === CustomerStatus::Inactive->value)
                                                                    ->maxLength(255),

                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->visible(fn(Get $get) => self::isBusinessType($get('type')))
                                                    ->schema([
                                                        Section::make('Bedrijfsgegevens')
                                                            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens'])
                                                            ->schema([
                                                                Select::make('type')
                                                                    ->columnSpan(3)
                                                                    ->label('Type')
                                                                    ->inlineLabel()
                                                                    ->extraAttributes(['class' => 'companySection-statusrequiredSelect'])
                                                                    ->options(CustomerType::visibleLabels())
                                                                    ->disabled()
                                                                    ->dehydrated()
                                                                    ->visible(fn(Get $get) => CustomerType::tryFrom($get('type'))?->isVisible() ?? false),
                                                                TextInput::make('name')
                                                                    ->columnSpan(3)
                                                                    ->label('Bedrijfsnaam')
                                                                    ->inlineLabel()
                                                                    ->required()
                                                                    ->live(onBlur: true)
                                                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                                                        self::syncShippingLocationNameFromCustomerName($get, $set);
                                                                    })
                                                                    ->maxLength(255),
                                                                TextInput::make('phone_number')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->label('Telefoonnummer')
                                                                    ->maxLength(255),
                                                                TextInput::make('kvk')
                                                                    ->columnSpan(3)
                                                                    ->label('KvK-nummer')
                                                                    ->inlineLabel()
                                                                    ->maxLength(255),
                                                                TextInput::make('vat')
                                                                    ->columnSpan(3)
                                                                    ->label('BTW-nummer')
                                                                    ->inlineLabel()
                                                                    ->maxLength(255)
                                                                    ->rules(['nullable', new ValidDutchVatNumber()])
                                                                    ->dehydrateStateUsing(fn (mixed $state): ?string => ValidDutchVatNumber::normalize($state)),
                                                                TextInput::make('iban')
                                                                    ->columnSpan(3)
                                                                    ->label('IBAN')
                                                                    ->inlineLabel()
                                                                    ->maxLength(255),
                                                                TextInput::make('bic')
                                                                    ->columnSpan(3)
                                                                    ->label('BIC')
                                                                    ->inlineLabel()
                                                                    ->maxLength(255),
                                                                Select::make('status')
                                                                    ->columnSpan(3)
                                                                    ->label('Status')
                                                                    ->inlineLabel()
                                                                    ->extraAttributes(['class' => 'companySection-statusrequiredSelect'])
                                                                    ->options(CustomerStatus::labelsForEditForm())
                                                                    ->default(CustomerStatus::Active)
                                                                    ->live()
                                                                    ->required(),
                                                                TextInput::make('reason_inactive')
                                                                    ->columnSpan(3)
                                                                    ->label('Reden inactief')
                                                                    ->required()
                                                                    ->inlineLabel()
                                                                    ->visible(fn(Get $get) => $get('status') === CustomerStatus::Inactive->value)
                                                                    ->maxLength(255),
                                                                TextInput::make('discount_percentage')
                                                                    ->columnSpan(3)
                                                                    ->label('Kortingspercentage')
                                                                    ->inlineLabel()
                                                                    ->numeric()
                                                                    ->minValue(0)
                                                                    ->maxValue(100)
                                                                    ->step(0.01)
                                                                    ->suffix('%')
                                                                    ->default(0),
                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->schema([
                                                        Section::make('Factuurgegevens')
                                                            ->extraAttributes(['class' => 'beheer-factuurgegevensSection'])
                                                            ->schema([
                                                                Group::make()
                                                                    ->columnSpan(3)
                                                                    ->visible(fn (Get $get): bool => self::isBusinessType($get('type')))
                                                                    ->schema([
                                                                        TextInput::make('last_name')
                                                                            ->columnSpan(3)
                                                                            ->label('Ter attentie van')
                                                                            ->inlineLabel()
                                                                            ->maxLength(255),
                                                                        TextInput::make('mobile_phone_number')
                                                                            ->columnSpan(3)
                                                                            ->inlineLabel()
                                                                            ->validationMessages(['size' => 'Ongeldig telefoonnummer'])
                                                                            ->label('Mobiel nummer')
                                                                            ->maxLength(255),
                                                                    ]),
                                                                Group::make()
                                                                    ->columnSpan(3)
                                                                    ->relationship('billingAddress')
                                                                    ->visible(fn(Get $get): bool => !self::isBusinessType($get('type')))
                                                                    ->schema(self::customerInvoiceAddressRelationshipSchema()),
                                                                Group::make()
                                                                    ->columnSpan(3)
                                                                    ->relationship('billingAddress')
                                                                    ->visible(fn(Get $get): bool => self::isBusinessType($get('type')))
                                                                    ->schema(array_merge(
                                                                        [
                                                                            TextInput::make('email')
                                                                                ->label('E-mail')
                                                                                ->email()
                                                                                ->columnSpan(3)
                                                                                ->required()
                                                                                ->inlineLabel()
                                                                                ->autocomplete()
                                                                                ->maxLength(255),
                                                                            Checkbox::make('newsletter_subscribed')
                                                                                ->columnSpan(3)
                                                                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap checkbox-wrap'])
                                                                                ->label('Inschrijven nieuwsbrief')
                                                                                ->inlineLabel()
                                                                                ->default(true)
                                                                                ->visible(fn (Get $get): bool => self::usesNewsletterDealerCustomerType($get('../type')))
                                                                                ->disabled(fn (Get $get): bool => self::isNewsletterFormChoiceLocked($get('../status')))
                                                                                ->dehydrated(fn (Get $get): bool => self::usesNewsletterDealerCustomerType($get('../type'))),
                                                                        ],
                                                                        self::customerInvoiceAddressRelationshipSchema(),
                                                                    )),
                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(4)
                                                    ->schema([
                                                        Section::make('Levergegevens')
                                                            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection'])
                                                            ->schema([
                                                                Select::make('delivery_address_type')
                                                                    ->label('Leveradres')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->default(fn (Get $get): string => self::isDealerType($get('type')) ? 'custom' : 'contact')
                                                                    ->selectablePlaceholder(false)
                                                                    ->live()
                                                                    ->options([
                                                                        'contact' => 'Zelfde als factuuradres',
                                                                        'custom' => 'Afwijkend',
                                                                    ])
                                                                    ->disabled(fn (Get $get): bool => self::isDealerType($get('type')))
                                                                    ->dehydrated()
                                                                    ->afterStateUpdated(function (Get $get, Set $set, $state, $record) {
                                                                        self::syncShippingAddress($get, $set, $record);
                                                                    }),

                                                                Group::make()
                                                                    ->columnSpan(3)
                                                                    ->relationship('shippingAddress')
                                                                    ->visible(fn(Get $get) => $get('delivery_address_type') !== 'contact')
                                                                    ->schema([
                                                                        TextInput::make('location_name')
                                                                            ->label('Locatienaam')
                                                                            ->required()
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            ->maxLength(255),
                                                                        TextInput::make('phone_number')
                                                                            ->label('Telefoonnummer')
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            ->validationMessages(['size' => 'Ongeldig telefoonnummer'])
                                                                            ->maxLength(255),
                                                                        TextInput::make('name')
                                                                            ->label('Ter attentie van')
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            //->required()
                                                                            ->visible(fn(Get $get) => self::isBusinessType($get('../type')))
                                                                            ->maxLength(255),
                                                                        TextInput::make('email')
                                                                            ->label('E-mailadres')
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            ->email()
                                                                            ->visible(fn(Get $get) => self::isBusinessType($get('../type')))
                                                                            ->maxLength(255),
                                                                        Checkbox::make('newsletter_subscribed')
                                                                            ->columnSpan(4)
                                                                            ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap checkbox-wrap'])
                                                                            ->label('Inschrijven nieuwsbrief')
                                                                            ->inlineLabel()
                                                                            ->default(true)
                                                                            ->visible(fn(Get $get) => self::usesNewsletterDealerCustomerType($get('../type')))
                                                                            ->disabled(fn(Get $get) => self::isNewsletterFormChoiceLocked($get('../status')))
                                                                            ->dehydrated(fn(Get $get) => self::usesNewsletterDealerCustomerType($get('../type'))),
                                                                        TextInput::make('mobile_phone_number')
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            ->label('Mobiel nummer')
                                                                            ->maxLength(255),
                                                                        TextInput::make('postcode')
                                                                            ->label('Postcode')
                                                                            ->columnSpan(4)
                                                                            ->live(onBlur: true)
                                                                            ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                                                                            ->afterStateUpdated(function (Get $get, Set $set, Field $component) {
                                                                                self::validatePostcode($get, $set, field: $component);
                                                                            })
                                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                                            ->debounce(100)
                                                                            ->inlineLabel()
                                                                            ->maxLength(7),
                                                                        TextInput::make('house_number')
                                                                            ->numeric()
                                                                            ->columnSpan(4)
                                                                            ->live(onBlur: true)
                                                                            ->debounce(1000)
                                                                            ->label('Huisnummer')
                                                                            ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                                                                            ->afterStateUpdated(function (Get $get, Set $set, Field $component) {
                                                                                self::validatePostcode($get, $set, field: $component);
                                                                            })
                                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                                            ->inlineLabel()
                                                                            ->maxLength(255),
                                                                        TextInput::make('house_number_addition')
                                                                            ->label('Toevoeging')
                                                                            ->debounce(1000)
                                                                            ->columnSpan(4)
                                                                            ->live(onBlur: true)
                                                                            ->afterStateUpdated(function (Get $get, Set $set, Field $component) {
                                                                                self::validatePostcode($get, $set, field: $component);
                                                                            })
                                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                                            ->inlineLabel()
                                                                            ->maxLength(255),
                                                                        TextInput::make('street')
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            ->label('Straatnaam')
                                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                                            ->maxLength(255),
                                                                        TextInput::make('city')
                                                                            ->label('Plaats')
                                                                            ->inlineLabel()
                                                                            ->columnSpan(4)
                                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                                            ->maxLength(255),
                                                                        Select::make('country_id')
                                                                            ->label('Land')
                                                                            ->required()
                                                                            ->inlineLabel()
                                                                            ->relationship(
                                                                                'country',
                                                                                'name',
                                                                                fn($query) => $query
                                                                            )
                                                                            ->default(Country::NL_ID)
                                                                            ->live()
                                                                            ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                                                                            ->columnSpan(4),
                                                                    ]),
                                                            ]),
                                                    ]),

                                                Html::make('<hr>')
                                                    ->columnSpan(12),

                                                Grid::make(1)
                                                    ->columnSpan(8)
                                                    ->schema([
                                                        Section::make('Informatie klant (intern)')
                                                            ->extraAttributes(['class' => 'beheer-shadowpointinstellingenSection'])
                                                            ->schema([
                                                                Textarea::make('comment')
                                                                    ->columnSpan(8)
                                                                    ->hiddenLabel()
                                                                    ->maxLength(65535)
                                                                    ->rows(6)
                                                                    ->extraFieldWrapperAttributes(['class' => 'inlineField']),
                                                            ]),
                                                    ]),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('Documenten')
                            ->key('documents')
                            ->schema([
                                Livewire::make(CompanyDocumentsWidget::class),
                            ]),

                        Tab::make('Notities')
                            ->key('notes')
                            ->schema([
                                Livewire::make(CustomerNotesWidget::class)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Units')
                            ->key('units')
                            ->schema([
                                Livewire::make(CustomerUnitsWidget::class)
                                    ->columnSpanFull()
                                    ->key(fn (): string => 'customer-units-widget-' . $this->record->getKey()),
                            ]),

                        Tab::make('Exact koppeling')
                            ->key('exactkoppeling')
                            ->schema([
                                Grid::make(12)
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(4)
                                            ->schema([
                                                Section::make('Exact koppeling')
                                                    ->extraAttributes(['class' => 'exactSection'])
                                                    ->schema([
                                                        Text::make('Als een klant wordt aangemaakt, ge-update of naar status Actief wordt gezet, volgt directe synchronisatie met Exact en wordt een debiteur aangemaakt (niet van toepassing op type RD — stamgegevens).')
                                                            ->columnSpanFull()
                                                            ->extraAttributes(['style' => '']),
                                                        TextInput::make('debtor_number')
                                                            ->columnSpan(3)
                                                            ->label('Debiteurnummer / code')
                                                            ->disabled()
                                                            ->inlineLabel()
                                                            ->placeholder('(wordt automatisch ingevuld)'),
                                                        TextInput::make('exact_id')
                                                            ->columnSpan(3)
                                                            ->label('Exact Online ID')
                                                            ->disabled()
                                                            ->inlineLabel()
                                                            ->placeholder('(wordt automatisch ingevuld)'),
                                                        Select::make('exact_payment_condition')
                                                            ->label('Betalingsconditie')
                                                            ->inlineLabel()
                                                            ->columnSpan(3)
                                                            ->default(ExactPaymentCondition::DEFAULT_PAYMENT_CONDITION_CODE)
                                                            ->options(function (): array {
                                                                $options = ExactPaymentCondition::getPaymentConditionsAsOptions();
                                                                $code = $this->record?->exact_payment_condition;
                                                                if (
                                                                    is_string($code)
                                                                    && $code !== ''
                                                                    && ! array_key_exists($code, $options)
                                                                ) {
                                                                    $options[$code] = $code.' (Exact — niet in lijst, sync betalingscondities)';
                                                                }

                                                                return $options;
                                                            }),
                                                        Select::make('exact_vat_code')
                                                            ->label('BTW-code: Verkoop')
                                                            ->inlineLabel()
                                                            ->columnSpan(3)
                                                            ->default(ExactVATCode::DEFAULT_SALES_VAT_CODE)
                                                            ->options(ExactVATCode::getSalesVatCodesAsOptions()),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                    ]),

            ]);
    }

    /**
     * When the user uploads files via the mail modal "(toevoegen)", add them to the document owner's media and merge into the form checklist.
     */
    public function updatedDocumentFiles(): void
    {
        if (empty($this->documentFiles)) {
            return;
        }

        $allowedMimes = config('documents.allowed_mime_types', []);
        $mimetypesRule = $allowedMimes !== [] ? 'mimetypes:' . implode(',', $allowedMimes) : 'file';
        $maxKb = 10240;

        try {
            $this->validate([
                'documentFiles' => 'required|array',
                'documentFiles.*' => 'file|' . $mimetypesRule . '|max:' . $maxKb,
            ]);
        } catch (ValidationException $e) {
            $this->documentFiles = [];
            $message = $e->validator->errors()->first();
            Notification::make()
                ->title('Ongeldige bestanden.')
                ->body($message ?: 'Controleer het bestandstype en de bestandsgrootte.')
                ->danger()
                ->send();

            return;
        }

        $owner = $this->record;
        if ($owner === null) {
            $this->documentFiles = [];
            return;
        }

        $newMediaIds = [];
        $count = 0;
        $rejected = [];

        foreach ($this->documentFiles as $file) {
            if (!$file) {
                continue;
            }
            $mime = $file->getMimeType();
            if ($allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
                $rejected[] = $file->getClientOriginalName();
                continue;
            }
            $media = $owner->addMedia($file->getRealPath())
                ->usingFileName($file->getClientOriginalName())
                ->toMediaCollection('documents');
            $newMediaIds[] = (string)$media->id;
            $count++;
        }

        $this->documentFiles = [];
        $owner->unsetRelation('media');

        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);

        if ($count > 0) {
            Notification::make()
                ->title($count === 1 ? 'Document geüpload.' : "{$count} documenten geüpload.")
                ->success()
                ->send();
        }
        if ($rejected !== []) {
            $names = implode(', ', array_slice($rejected, 0, 5));
            if (count($rejected) > 5) {
                $names .= ' … (+' . (count($rejected) - 5) . ' meer)';
            }
            Notification::make()
                ->title('Bestandstype niet toegestaan.')
                ->body('Overgeslagen: ' . $names)
                ->danger()
                ->send();
        }
    }

    #[On('documents-uploaded')]
    public function onDocumentsUploaded(mixed ...$args): void
    {
        $newMediaIds = $this->normalizeDocumentsUploadedPayload($args);
        $this->mergeNewUploadedAttachmentsIntoMountedAction($newMediaIds);
    }

    /**
     * Normalize event payload: Livewire may pass named params as first argument (array of IDs) or as single array with key newMediaIds.
     *
     * @param array<mixed> $args
     * @return array<int|string>
     */
    protected function normalizeDocumentsUploadedPayload(array $args): array
    {
        $first = $args[0] ?? null;
        if (is_array($first) && array_key_exists('newMediaIds', $first)) {
            $first = $first['newMediaIds'];
        }
        if (!is_array($first)) {
            return [];
        }
        return array_values(array_map(fn($id) => is_int($id) ? $id : (string)$id, $first));
    }

    /**
     * Form components for the customer's invoice address relationship (address or billingAddress).
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function customerInvoiceAddressRelationshipSchema(): array
    {
        return [
            TextInput::make('postcode')
                ->label('Postcode')
                ->columnSpan(4)
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, Field $component, $state, $record = null) {
                    self::validatePostcode($get, $set, field: $component);
                    self::syncShippingAddress($get, $set, $record);
                })
                ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                ->debounce(100)
                ->inlineLabel()
                ->maxLength(7),
            TextInput::make('house_number')
                ->numeric()
                ->columnSpan(4)
                ->reactive()
                ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                ->debounce(1000)
                ->label('Huisnummer')
                ->afterStateUpdated(function (Get $get, Set $set, Field $component, $state = null, $record = null) {
                    self::validatePostcode($get, $set, field: $component);
                    self::syncShippingAddress($get, $set, $record);
                })
                ->inlineLabel()
                ->maxLength(255),
            TextInput::make('house_number_addition')
                ->label('Toevoeging')
                ->debounce(1000)
                ->columnSpan(4)
                ->afterStateUpdated(function (Get $get, Set $set, Field $component, $state = null, $record = null) {
                    self::validatePostcode($get, $set, field: $component);
                    self::syncShippingAddress($get, $set, $record);
                })
                ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                ->reactive()
                ->inlineLabel()
                ->maxLength(255),

            TextInput::make('street')
                ->columnSpan(4)
                ->inlineLabel()
                ->label('Straatnaam')
                ->readOnly(function (Get $get) {
                    return (int)$get('country_id') == Country::NL_ID;
                })
                ->afterStateUpdated(fn(Get $get, Set $set, $state = null, $record = null) => self::syncShippingAddress($get, $set, $record))
                ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                ->maxLength(255),
            TextInput::make('city')
                ->label('Plaats')
                ->inlineLabel()
                ->readOnly(function (Get $get) {
                    return (int)$get('country_id') == Country::NL_ID;
                })
                ->afterStateUpdated(fn(Get $get, Set $set, $state = null, $record = null) => self::syncShippingAddress($get, $set, $record))
                ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                ->columnSpan(4)
                ->maxLength(255),

            Select::make('country_id')
                ->label('Land')
                ->required()
                ->inlineLabel()
                ->relationship(
                    'country',
                    'name',
                    fn($query) => $query
                )
                ->default(Country::NL_ID)
                ->reactive()
                ->afterStateUpdated(fn(Get $get, Set $set, $state = null, $record = null) => self::syncShippingAddress($get, $set, $record))
                ->extraAttributes(fn (Get $get, Field $component): array => self::postcodeValidationExtraAttributes($get, $component))
                ->columnSpan(4),
        ];
    }

    /**
     * When the "Mail customer" action modal is open, add newly uploaded document media IDs to the form's uploaded_attachments so they appear checked.
     */
    protected function mergeNewUploadedAttachmentsIntoMountedAction(array $newMediaIds): void
    {
        if ($newMediaIds === [] || empty($this->mountedActions)) {
            return;
        }

        $index = null;
        foreach ($this->mountedActions as $key => $mounted) {
            if (!is_array($mounted)) {
                continue;
            }
            if (($mounted['name'] ?? null) === 'send_customer_email') {
                $index = $key;
                break;
            }
            if (isset($mounted['data']['uploaded_attachments'])) {
                $index = $key;
                break;
            }
        }
        if ($index === null) {
            return;
        }

        if (!array_key_exists('data', $this->mountedActions[$index])) {
            $this->mountedActions[$index]['data'] = [];
        }

        $current = $this->mountedActions[$index]['data']['uploaded_attachments'] ?? [];
        $current = is_array($current) ? $current : [];
        $merged = array_values(array_unique(array_merge($current, $newMediaIds)));
        $this->mountedActions[$index]['data']['uploaded_attachments'] = $merged;
    }
}

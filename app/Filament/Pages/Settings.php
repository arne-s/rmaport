<?php

namespace App\Filament\Pages;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Settings\PaymentSettingsTab;
use App\Filament\Resources\CustomerResource;
use App\Http\Livewire\FormImportSettings;
use App\Http\Livewire\MicrosoftMailConnect;
use App\Http\Livewire\MicrosoftMailSenderProfiles;
use App\Models\Country;
use App\Models\Customer;
use App\Models\MicrosoftMailToken;
use App\Models\Setting;
use App\Services\OutlookExternalConnectInviteService;
use App\Services\SettingService;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Livewire as FilamentLivewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * @property Customer $record
 */
class Settings extends EditRecord
{
    use PostcodeValidatorTrait;

    protected static string $resource = CustomerResource::class;

    public function mount(int|string $record = null): void
    {
        parent::mount(Customer::getAvCustomer()->id);
    }

    protected function resolveRecord(int|string $key): Customer
    {
        return Customer::getAvCustomer();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->can('manage settings') ?? false, 403);
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('manage settings') ?? false;
    }

    public static function authorizeResourceAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $data['status'] = CustomerStatus::Active->value;
        $data['reason_inactive'] = null;
        $data['settings'] = Setting::allAsFormState();

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['status'] = CustomerStatus::Active->value;
        $data['reason_inactive'] = null;

        return $data;
    }

    public function saveSetting(string $uid, mixed $value): void
    {
        try {
            app(SettingService::class)->saveFromFormState([$uid => $value]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?? 'De waarde is ongeldig.';

            Notification::make()
                ->danger()
                ->title('Instelling niet opgeslagen')
                ->body($message)
                ->send();
        }
    }

    public function getTitle(): string
    {
        return 'Stamgegevens';
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.app.pages.dashboard') => 'Terug naar Dashboard',
            url()->current() => 'Stamgegevens',
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        // Stay on the current tab (Alpine persists ?area= in the URL; a redirect would drop it on Livewire POST).
        return null;
    }

    protected function afterSave(): void
    {
        $this->dispatch('profile-saved');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                    ]),

                Html::make(view('filament.partials.microsoft-oauth-flash')->render()),
                Tabs::make('Tabs')
                    ->persistTabInQueryString('area')
                    ->tabs([
                        Tab::make('Algemene gegevens')
                            ->key('data')
                            ->icon(Heroicon::OutlinedCog6Tooth)
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
                                                        Section::make('Contactpersoon')
                                                            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens'])
                                                            ->schema([
                                                                Select::make('salutation')
                                                                    ->label('Aanhef')
                                                                    ->placeholder('Selecteer')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->options([
                                                                        'Dhr.' => 'Dhr.',
                                                                        'Mevr.' => 'Mevr.',
                                                                    ]),
                                                                TextInput::make('first_name')
                                                                    ->label('Voornaam')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel(),
                                                                TextInput::make('middle_name')
                                                                    ->label('Tussenvoegsel')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel(),
                                                                TextInput::make('last_name')
                                                                    ->label('Achternaam')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->required(),
                                                                TextInput::make('email')
                                                                    ->label('E-mail')
                                                                    ->email()
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->autocomplete(),
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
                                                                Select::make('type')
                                                                    ->columnSpan(3)
                                                                    ->label('Type')
                                                                    ->inlineLabel()
                                                                    ->extraAttributes(['class' => 'companySection-statusrequiredSelect'])
                                                                    ->options(CustomerType::visibleLabels())
                                                                    ->visible(fn(Get $get) => CustomerType::tryFrom($get('type'))?->isVisible() ?? false),
                                                                Checkbox::make('newsletter_subscribed')
                                                                    ->columnSpan(3)
                                                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap checkbox-wrap'])
                                                                    ->label('Inschrijven nieuwsbrief')
                                                                    ->inlineLabel()
                                                                    ->default(true)
                                                                    ->dehydrated(true),
                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(8)
                                                    ->visible(fn(Get $get) => self::isBusinessType($get('type')))
                                                    ->schema([
                                                        Section::make('Gegevens | Offertes, orders, facturen, afleverbon, inkoop, etc')
                                                            ->extraAttributes(['class' => 'beheer-bedrijfsgegevensSection header-bedrijfsgegevens'])
                                                            ->schema([
                                                                Select::make('type')
                                                                    ->columnSpan(3)
                                                                    ->label('Type')
                                                                    ->inlineLabel()
                                                                    ->extraAttributes(['class' => 'companySection-statusrequiredSelect'])
                                                                    ->options(CustomerType::visibleLabels())
                                                                    ->visible(fn(Get $get) => CustomerType::tryFrom($get('type'))?->isVisible() ?? false),
                                                                TextInput::make('name')
                                                                    ->columnSpan(3)
                                                                    ->label('Bedrijfsnaam')
                                                                    ->inlineLabel()
                                                                    ->required()
                                                                    ->maxLength(255),

                                                                Group::make()
                                                                    ->columnSpan(3)
                                                                    ->relationship('billingAddress')
                                                                    ->schema([
                                                                        TextInput::make('postcode')
                                                                            ->label('Postcode')
                                                                            ->columnSpan(4)
                                                                            ->live()
                                                                            ->afterStateUpdated(function (Get $get, Set $set, Field $component, $state, $record = null) {
                                                                                self::validatePostcode($get, $set, field: $component);
                                                                                self::syncShippingAddress($get, $set, $record);
                                                                            })
                                                                            ->extraAttributes(function (Get $get, Field $component) {
                                                                                $resp = self::validatePostcode($get, field: $component);
                                                                                if ($resp === null) {
                                                                                    return [];
                                                                                }

                                                                                return ['class' => $resp === false ? 'invalid' : 'valid'];
                                                                            })
                                                                            ->debounce(100)
                                                                            ->inlineLabel()
                                                                            ->maxLength(7),
                                                                        TextInput::make('house_number')
                                                                            ->numeric()
                                                                            ->columnSpan(4)
                                                                            ->reactive()
                                                                            ->extraAttributes(function (Get $get, Field $component) {
                                                                                $resp = self::validatePostcode($get, field: $component);
                                                                                if ($resp === null) {
                                                                                    return [];
                                                                                }

                                                                                return ['class' => $resp === false ? 'invalid' : 'valid'];
                                                                            })
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
                                                                            ->extraAttributes(function (Get $get, Field $component) {
                                                                                $resp = self::validatePostcode($get, field: $component);
                                                                                if ($resp === null) {
                                                                                    return [];
                                                                                }

                                                                                return ['class' => $resp === false ? 'invalid' : 'valid'];
                                                                            })
                                                                            ->reactive()
                                                                            ->inlineLabel()
                                                                            ->maxLength(255),
                                                                        TextInput::make('street')
                                                                            ->columnSpan(4)
                                                                            ->inlineLabel()
                                                                            ->label('Straatnaam')
                                                                            ->afterStateUpdated(fn(Get $get, Set $set, $state = null, $record = null) => self::syncShippingAddress($get, $set, $record))
                                                                            ->extraAttributes(function (Get $get, Field $component) {
                                                                                $resp = self::validatePostcode($get, field: $component);
                                                                                if ($resp === null) {
                                                                                    return [];
                                                                                }

                                                                                return ['class' => $resp === false ? 'invalid' : 'valid'];
                                                                            })
                                                                            ->maxLength(255),
                                                                        TextInput::make('city')
                                                                            ->label('Plaats')
                                                                            ->inlineLabel()
                                                                            ->afterStateUpdated(fn(Get $get, Set $set, $state = null, $record = null) => self::syncShippingAddress($get, $set, $record))
                                                                            ->extraAttributes(function (Get $get, Field $component) {
                                                                                $resp = self::validatePostcode($get, field: $component);
                                                                                if ($resp === null) {
                                                                                    return [];
                                                                                }

                                                                                return ['class' => $resp === false ? 'invalid' : 'valid'];
                                                                            })
                                                                            ->columnSpan(4)
                                                                            ->maxLength(255),
                                                                        Select::make('country_id')
                                                                            ->label('Land')
                                                                            ->required()
                                                                            ->inlineLabel()
                                                                            ->relationship('country', 'name', fn($query) => $query)
                                                                            ->default(Country::NL_ID)
                                                                            ->reactive()
                                                                            ->afterStateUpdated(fn(Get $get, Set $set, $state = null, $record = null) => self::syncShippingAddress($get, $set, $record))
                                                                            ->extraAttributes(function (Get $get, Field $component) {
                                                                                $resp = self::validatePostcode($get, field: $component);
                                                                                if ($resp === null) {
                                                                                    return [];
                                                                                }

                                                                                return ['class' => $resp === false ? 'invalid' : 'valid'];
                                                                            })
                                                                            ->columnSpan(4),
                                                                    ]),
                                                                TextInput::make('kvk')
                                                                    ->columnSpan(3)
                                                                    ->label('KvK-nummer')
                                                                    ->inlineLabel()
                                                                    ->maxLength(255),


                                                                TextInput::make('vat')
                                                                    ->columnSpan(3)
                                                                    ->label('BTW-nummer')
                                                                    ->inlineLabel()
                                                                    ->maxLength(255),
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
                                                                TextInput::make('email')
                                                                    ->columnSpan(3)
                                                                    ->label('E-mailadres')
                                                                    ->email()
                                                                    ->inlineLabel()
                                                                    ->maxLength(255),
                                                                TextInput::make('phone_number')
                                                                    ->columnSpan(3)
                                                                    ->inlineLabel()
                                                                    ->label('Telefoonnummer')
                                                                    ->maxLength(255),
                                                            ]),
                                                    ]),

                                                Html::make('<hr>')
                                                    ->columnSpan(12),

                                            ]),
                                    ]),
                            ]),

                        Tab::make('Betalingsinstellingen')
                            ->key('payment')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->schema(PaymentSettingsTab::schema()),

                        Tab::make('Formulier-import')
                            ->key('form-import')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->schema([
                                FilamentLivewire::make(FormImportSettings::class)
                                    ->key('form-import-settings'),
                            ]),

                        Tab::make('Outlook: e-mailaccounts')
                            ->key('outlook-mail')
                            ->icon(Heroicon::Envelope)
                            ->schema(function (): array {
                                $tokens = MicrosoftMailToken::orderBy('id')->get();

                                $sections = $tokens->map(function (MicrosoftMailToken $token): Section {
                                    $label = $token->microsoft_email ?? ('Account ' . $token->id);

                                    return Section::make($label)
                                        ->collapsible()
                                        ->schema([
                                            FilamentLivewire::make(MicrosoftMailConnect::class, ['tokenId' => $token->id])
                                                ->key('microsoft-mail-connect-' . $token->id),
                                        ]);
                                })->all();

                                $sections[] = Html::make(
                                    '<a href="' . route('microsoft-mail.connect') . '" class="fi-btn fi-btn-size-sm fi-btn-color-primary fi-color-primary inline-grid">'
                                    . '<span class="fi-btn-label">Nieuw Outlook e-mailaccount toevoegen</span>'
                                    . '</a>'
                                );

                                if ($tokens->isNotEmpty()) {
                                    $sections[] = Section::make('Verzendprofielen')
                                        ->schema([
                                            FilamentLivewire::make(MicrosoftMailSenderProfiles::class)
                                                ->key('microsoft-mail-sender-profiles'),
                                        ]);
                                }

                                $sections[] = Html::make(self::externalOutlookInviteHtml());

                                return $sections;
                            }),

                    ]),

            ]);
    }

    private static function isBusinessType(?string $type): bool
    {
        if ($type === null) {
            return false;
        }

        $case = CustomerType::tryFrom($type);

        return $case !== null && $case->isBusiness();
    }

    private static function externalOutlookInviteHtml(): string
    {
        $link = app(OutlookExternalConnectInviteService::class)->getConnectUrl();
        $safeLink = e($link);

        return '<div style="margin-top:16px">'
            . '<strong>Externe koppellink</strong>'
            . '<p style="margin:6px 0 10px;color:#64748b;font-size:13px">Deel deze link met externe partijen. Zij kunnen hiermee een Outlook-account koppelen zonder in te loggen binnen het ERP.</p>'
            . '<input type="text" readonly value="' . $safeLink . '"'
            . ' style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \\"Liberation Mono\\", \\"Courier New\\", monospace;font-size:12px"'
            . ' onclick="this.select();" />'
            . '</div>';
    }

    private static function syncShippingAddress(Get $get, Set $set, mixed $record = null): void
    {
        $deliveryType = $get('delivery_address_type');

        if ($deliveryType === 'contact') {
            $set('shippingAddress.postcode', $get('billingAddress.postcode') ?? $record?->billingAddress?->postcode);
            $set('shippingAddress.house_number', $get('billingAddress.house_number') ?? $record?->billingAddress?->house_number);
            $set('shippingAddress.house_number_addition', $get('billingAddress.house_number_addition') ?? $record?->billingAddress?->house_number_addition);
            $set('shippingAddress.country_id', $get('billingAddress.country_id') ?? $record?->billingAddress?->country_id);
            $set('shippingAddress.street', $get('billingAddress.street') ?? $record?->billingAddress?->street);
            $set('shippingAddress.city', $get('billingAddress.city') ?? $record?->billingAddress?->city);
        } elseif ($deliveryType === 'custom') {
            $set('shippingAddress.postcode', null);
            $set('shippingAddress.house_number', null);
            $set('shippingAddress.house_number_addition', null);
            $set('shippingAddress.country_id', Country::NL_ID);
            $set('shippingAddress.street', null);
            $set('shippingAddress.city', null);
        }
    }
}

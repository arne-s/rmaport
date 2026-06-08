<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Models\Order\Main;
use App\Models\PostNLShipment;
use App\Services\PostNLService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class RegisterPostNLShipmentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'register_postnl_shipment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->icon('heroicon-o-truck')
            ->label('Verzendlabel')
            ->modalHeading('Pakket aanmelden bij PostNL')
            ->closeModalByClickingAway(false)
            ->modalSubmitActionLabel('Aanmelden')
            ->modalWidth('2xl')
            ->schema(fn($livewire) => $this->buildSchema($livewire->record))
            ->action(fn(array $data, $livewire) => $this->handleSubmit($data, $livewire->record, $livewire));
    }

    /** @return array<int, mixed> */
    private function buildSchema(Main $record): array
    {
        $recipientOptions = $this->buildRecipientOptions($record);

        return [
            TextEntry::make('postnl_error_display')
                ->hiddenLabel()
                ->state(new HtmlString(
                    '<div x-data="{ message: \'\' }" @postnl-error.window="message = $event.detail.message">'
                        . '<div x-show="message" x-text="message" class="rounded-lg bg-danger-50 border border-danger-200 px-4 py-3 text-sm text-danger-700"></div>'
                        . '</div>'
                )),

            Section::make('Verzendinstellingen')
                ->ExtraAttributes(['class' => 'shipment-section'])
                ->schema([
                    Select::make('parcel_type')
                        ->label('Wat ga je versturen?')
                        ->options([
                            'parcel'  => 'Pakket (max. 175 × 78 × 58 cm, max. 31,5 kg)',
                            'mailbox' => 'Brievenbuspakje (max. 38 × 26,5 × 3,2 cm, max. 2 kg)',
                        ])
                        ->default('parcel')
                        ->required()
                        ->live(),

                    Select::make('proof_of_delivery')
                        ->label('Afleverbewijs')
                        ->options([
                            'none'      => 'Geen afleverbewijs',
                            'signature' => 'Handtekening voor ontvangst',
                        ])
                        ->default('none')
                        ->required()
                        ->hidden(fn(Get $get): bool => $get('parcel_type') === 'mailbox'),

                    DatePicker::make('collection_date')
                        ->label('Wanneer lever je de zending aan ons aan?')
                        ->default(today())
                        ->minDate(today())
                        ->required(),
                ]),

            Section::make('Ontvanger')
                ->ExtraAttributes(['class' => 'shipment-receiver-section'])
                ->schema([
                    Select::make('recipient_mode')
                        ->label('Verzenden naar')
                        ->options($recipientOptions)
                        ->default(array_key_first($recipientOptions))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (string $state, Set $set) use ($record): void {
                            $this->prefillAddressFields($state, $record, $set);
                        }),

                    Grid::make(2)->schema([
                        TextInput::make('recipient_name')
                            ->label('Naam ontvanger')
                            ->required()
                            ->default($this->defaultName($record))
                            ->maxLength(35),

                        TextInput::make('recipient_company')
                            ->label('Bedrijfsnaam')
                            ->default($this->defaultCompany($record))
                            ->maxLength(35),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('recipient_street')
                            ->label('Straat')
                            ->required()
                            ->default($record->shippingAddress?->getStreet())
                            ->columnSpan(1),

                        TextInput::make('recipient_house_nr')
                            ->label('Huisnr')
                            ->required()
                            ->default($record->shippingAddress?->getHouseNumber()),

                        TextInput::make('recipient_house_nr_addition')
                            ->label('Toevoeging')
                            ->default($record->shippingAddress?->getHouseNumberAddition()),
                    ]),

                    Grid::make(3)->schema([
                        TextInput::make('recipient_postcode')
                            ->label('Postcode')
                            ->required()
                            ->default($record->shippingAddress?->getPostcode()),

                        TextInput::make('recipient_city')
                            ->label('Stad')
                            ->required()
                            ->default($record->shippingAddress?->getCity()),

                        Select::make('recipient_country')
                            ->label('Land')
                            ->required()
                            ->default($this->defaultCountry($record))
                            ->options([
                                'NL' => 'Nederland',
                                'BE' => 'België',
                                'DE' => 'Duitsland',
                                'FR' => 'Frankrijk',
                                'LU' => 'Luxemburg',
                                'GB' => 'Verenigd Koninkrijk',
                                'AT' => 'Oostenrijk',
                                'CH' => 'Zwitserland',
                                'DK' => 'Denemarken',
                                'ES' => 'Spanje',
                                'FI' => 'Finland',
                                'GR' => 'Griekenland',
                                'HU' => 'Hongarije',
                                'IE' => 'Ierland',
                                'IT' => 'Italië',
                                'NO' => 'Noorwegen',
                                'PL' => 'Polen',
                                'PT' => 'Portugal',
                                'SE' => 'Zweden',
                            ])
                            ->searchable(),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('recipient_phone')
                            ->label('Telefoonnummer')
                            ->default($record->customer?->getAvailablePhoneNumber()),

                        TextInput::make('recipient_email')
                            ->label('E-mailadres')
                            ->email()
                            ->default($record->customer?->getEmail()),
                    ]),
                ]),

            Section::make('Zending')
                ->ExtraAttributes(['class' => 'shipment-receiver-section'])
                ->schema([
                    TextInput::make('reference')
                        ->label('Referentie')
                        ->maxLength(35),

                    $this->buildReferenceSuggestions($record),

                    TextInput::make('remark')
                        ->label('Opmerking')
                        ->maxLength(35),

                    Checkbox::make('add_return_label')
                        ->label('Retourlabel toevoegen')
                        ->default(true)
                        ->inline(),
                ]),

        ];
    }

    private function buildReferenceSuggestions(Main $record): TextEntry
    {
        $suggestions = [];

        if ($uid = $record->getUidFormatted()) {
            $suggestions['Aanvraagnummer'] = $uid;
        }

        if ($poRef = $record->getReference()) {
            $suggestions[$record->billingCustomer?->getType()?->isBusiness() ? 'Inkoopordernummer' : 'Uw referentie (klant)'] = $poRef;
        }

        if ($refInternal = $record->getReferenceInternal()) {
            $suggestions['Referentie (intern)'] = $refInternal;
        }

        $orderUid = $record->orders()->latest()->first()?->getUidFormatted();
        if ($orderUid) {
            $suggestions['Ordernummer'] = $orderUid;
        }

        $html = '';
        if (! empty($suggestions)) {
            $links = collect($suggestions)
                ->map(function (string $value, string $label): string {
                    $rawJson  = json_encode($value);
                    $handler  = htmlspecialchars(
                        "let cur = (\$wire.get('mountedActions.0.data.reference') || '').trim();"
                            . " \$wire.set('mountedActions.0.data.reference', cur ? cur + ' ' + {$rawJson} : {$rawJson})",
                        ENT_QUOTES | ENT_HTML5,
                    );

                    return '<a href="#"'
                        . ' style="font-size:0.75rem;color:#3b82f6;text-decoration:underline;line-height:30px;"'
                        . ' x-on:click.prevent="' . $handler . '">'
                        . e(mb_strtolower($label))
                        . '</a>';
                })
                ->implode('<span style="font-size:0.75rem;color:#6b7280;">,&nbsp;</span>');

            $html = '<div style="margin-top:-0.5rem;">' . $links . '</div>';
        }

        return TextEntry::make('reference_suggestions')
            ->hiddenLabel()
            ->state(new HtmlString($html));
    }

    private function prefillAddressFields(string $mode, Main $record, Set $set): void
    {
        match ($mode) {
            'customer' => $this->prefillFromCustomer($record, $set),
            'dealer'   => $this->prefillFromDealer($record, $set),
            default    => $this->clearAddressFields($set),
        };
    }

    private function prefillFromCustomer(Main $record, Set $set): void
    {
        $addr = $record->shippingAddress;

        $set('recipient_name', $addr?->getName() ?? $record->customer?->getName() ?? '');
        $set('recipient_company', '');
        $set('recipient_street', $addr?->getStreet() ?? '');
        $set('recipient_house_nr', $addr?->getHouseNumber() ?? '');
        $set('recipient_house_nr_addition', $addr?->getHouseNumberAddition() ?? '');
        $set('recipient_postcode', $addr?->getPostcode() ?? '');
        $set('recipient_city', $addr?->getCity() ?? '');
        $set('recipient_country', $this->defaultCountry($record));
        $set('recipient_phone', $record->customer?->getAvailablePhoneNumber() ?? '');
        $set('recipient_email', $record->customer?->getEmail() ?? '');
    }

    private function prefillFromDealer(Main $record, Set $set): void
    {
        $company = $record->billingCustomer;
        $addr    = $company?->shippingAddress;

        $set('recipient_name', '');
        $set('recipient_company', $company?->getName() ?? '');
        $set('recipient_street', $addr?->getStreet() ?? '');
        $set('recipient_house_nr', $addr?->getHouseNumber() ?? '');
        $set('recipient_house_nr_addition', $addr?->getHouseNumberAddition() ?? '');
        $set('recipient_postcode', $addr?->getPostcode() ?? '');
        $set('recipient_city', $addr?->getCity() ?? '');
        $set('recipient_country', $addr ? ($addr->country?->code ? strtoupper($addr->country->code) : 'NL') : 'NL');
        $set('recipient_phone', $company?->getPhoneNumber() ?? '');
        $set('recipient_email', $company?->getEmail() ?? '');
    }

    private function clearAddressFields(Set $set): void
    {
        foreach (
            [
                'recipient_name',
                'recipient_company',
                'recipient_street',
                'recipient_house_nr',
                'recipient_house_nr_addition',
                'recipient_postcode',
                'recipient_city',
                'recipient_phone',
                'recipient_email',
            ] as $field
        ) {
            $set($field, '');
        }
        $set('recipient_country', 'NL');
    }

    private function handleSubmit(array $data, Main $record, mixed $livewire): void
    {
        $countryCode = strtoupper($data['recipient_country'] ?? 'NL');

        try {
            $service = app(PostNLService::class);

            $parcelType = $data['parcel_type'] ?? 'parcel';
            $requireSignature = ($data['proof_of_delivery'] ?? 'none') === 'signature';
            $collectionDate = isset($data['collection_date'])
                ? Carbon::parse($data['collection_date'])->format('Y-m-d')
                : null;

            $weightGrams = (int) ($data['weight'] ?? 1000);

            $barcode = $service->generateBarcode($countryCode, $parcelType);

            $labelBase64 = $service->generateLabel(
                barcode: $barcode,
                receiver: [
                    'name'              => $data['recipient_name'],
                    'company'           => $data['recipient_company'] ?? null,
                    'street'            => $data['recipient_street'],
                    'house_nr'          => $data['recipient_house_nr'],
                    'house_nr_addition' => $data['recipient_house_nr_addition'] ?? null,
                    'postcode'          => $data['recipient_postcode'],
                    'city'              => $data['recipient_city'],
                    'country'           => $countryCode,
                    'email'             => $data['recipient_email'] ?: null,
                    'phone'             => $data['recipient_phone'] ?? null,
                    'weight'            => $weightGrams,
                ],
                reference: $data['reference'] ?? '',
                remark: $data['remark'] ?? '',
                parcelType: $parcelType,
                requireSignature: $requireSignature,
                collectionDate: $collectionDate,
            );

            PostNLShipment::create([
                'order_id'                   => $record->getId(),
                'barcode'                    => $barcode,
                'recipient_name'             => $data['recipient_name'],
                'recipient_company'          => $data['recipient_company'] ?? null,
                'recipient_street'           => $data['recipient_street'],
                'recipient_house_nr'         => $data['recipient_house_nr'],
                'recipient_house_nr_addition' => $data['recipient_house_nr_addition'] ?? null,
                'recipient_postcode'         => $data['recipient_postcode'],
                'recipient_city'             => $data['recipient_city'],
                'recipient_country'          => $countryCode,
                'reference'                  => $data['reference'] ?? null,
            ]);

            $record
                ->addMediaFromString(base64_decode($labelBase64, true))
                ->usingFileName('postnl-label-' . $barcode . '.pdf')
                ->usingName('PostNL label — ' . $barcode)
                ->withCustomProperties(['barcode' => $barcode, 'source' => 'postnl', 'readonly' => true])
                ->toMediaCollection('delivery_documents');

            $outboundBarcode = $barcode;
            $returnBarcode = null;
            $returnLabelFailed = false;

            if (! empty($data['add_return_label'])) {
                try {
                    $returnBarcode = $service->generateBarcode('NL', $parcelType);
                    $returnReceiver = $service->senderAddressToReceiverArray($weightGrams);
                    $returnRemark = $this->buildReturnRemarkForOutboundBarcode($outboundBarcode);

                    $returnLabelBase64 = $service->generateLabel(
                        barcode: $returnBarcode,
                        receiver: $returnReceiver,
                        reference: $data['reference'] ?? '',
                        remark: $returnRemark,
                        parcelType: $parcelType,
                        requireSignature: $requireSignature,
                        collectionDate: $collectionDate,
                        shipmentSenderAddress: [
                            'name' => $data['recipient_name'],
                            'company' => $data['recipient_company'] ?? null,
                            'street' => $data['recipient_street'],
                            'house_nr' => $data['recipient_house_nr'],
                            'house_nr_addition' => $data['recipient_house_nr_addition'] ?? null,
                            'postcode' => $data['recipient_postcode'],
                            'city' => $data['recipient_city'],
                            'country' => $countryCode,
                        ],
                    );

                    /** @var array{company: string, street: string, house_nr: string, postcode: string, city: string, country?: string} $avSender */
                    $avSender = config('postnl.sender');

                    PostNLShipment::create([
                        'order_id'                   => $record->getId(),
                        'barcode'                    => $returnBarcode,
                        'recipient_name'             => '',
                        'recipient_company'          => $avSender['company'],
                        'recipient_street'           => $avSender['street'],
                        'recipient_house_nr'         => $avSender['house_nr'],
                        'recipient_house_nr_addition' => null,
                        'recipient_postcode'         => $avSender['postcode'],
                        'recipient_city'             => $avSender['city'],
                        'recipient_country'          => strtoupper($avSender['country'] ?? 'NL'),
                        'reference'                  => $data['reference'] ?? null,
                    ]);

                    $record
                        ->addMediaFromString(base64_decode($returnLabelBase64, true))
                        ->usingFileName('postnl-retour-label-' . $returnBarcode . '.pdf')
                        ->usingName('PostNL retourlabel — ' . $returnBarcode)
                        ->withCustomProperties([
                            'barcode' => $returnBarcode,
                            'source' => 'postnl',
                            'readonly' => true,
                            'kind' => 'return',
                            'outbound_barcode' => $outboundBarcode,
                        ])
                        ->toMediaCollection('delivery_documents');
                } catch (\RuntimeException $e) {
                    $returnLabelFailed = true;
                    $returnBarcode = null;

                    $livewire->dispatch('postnl-error', message: $e->getMessage());

                    Notification::make()
                        ->title('Retourlabel niet aangemaakt')
                        ->body(
                            'Het verzendlabel is wel aangemaakt (barcode: ' . $outboundBarcode . '). '
                            . $e->getMessage(),
                        )
                        ->warning()
                        ->send();
                }
            }

            $livewire->dispatch('postnl-label-created');

            if (! $returnLabelFailed) {
                if ($returnBarcode !== null) {
                    Notification::make()
                        ->title('Pakket aangemeld')
                        ->body(
                            'Verzendlabel: ' . $outboundBarcode . '. Retourlabel: ' . $returnBarcode . '.',
                        )
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Pakket aangemeld')
                        ->body('Barcode: ' . $outboundBarcode . '.')
                        ->success()
                        ->send();
                }
            }
        } catch (\RuntimeException $e) {
            $livewire->dispatch('postnl-error', message: $e->getMessage());
            $this->halt();
        }
    }

    /**
     * PostNL shipment remark is limited to 35 characters.
     */
    private function buildReturnRemarkForOutboundBarcode(string $outboundBarcode): string
    {
        $full = 'Retour voor ' . $outboundBarcode;

        if (mb_strlen($full) <= 35) {
            return $full;
        }

        $prefix = 'Retour ';

        return $prefix . mb_substr($outboundBarcode, 0, max(0, 35 - mb_strlen($prefix)));
    }

    /** @return array<string, string> */
    private function buildRecipientOptions(Main $record): array
    {
        $options = [];

        $customerLabel = 'Klant (leveradres)';
        if ($record->customer !== null) {
            $display = $record->getCustomerAddressDisplayName();
            if ($display === '') {
                $display = $record->customer->getName();
            }
            $customerLabel = 'Klant — '.$display;
        }
        $options['customer'] = $customerLabel;

        $billingCustomer = $record->billingCustomer;
        if ($billingCustomer !== null && $billingCustomer->getType()?->isBusiness()) {
            $dealerLabel = 'Dealer — ' . $billingCustomer->getName();
            if ($billingCustomer->billingAddress === null) {
                $dealerLabel .= ' (geen adres)';
            }
            $options['dealer'] = $dealerLabel;
        }

        $options['custom'] = 'Zelf invullen';

        return $options;
    }

    private function defaultName(Main $record): string
    {
        return $record->shippingAddress?->getName()
            ?? $record->customer?->getName()
            ?? '';
    }

    private function defaultCompany(Main $record): string
    {
        return '';
    }

    private function defaultCountry(Main $record): string
    {
        $code = $record->shippingAddress?->country?->code;

        return $code ? strtoupper($code) : 'NL';
    }
}

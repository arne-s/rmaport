<?php

namespace App\Filament\Imports;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PaymentTerms;
use App\Models\Customer;
use App\Models\Setting;
use App\Rules\ValidDutchVatNumber;
use App\Services\CustomerCsvAddressImporter;
use App\Support\CustomerCsvSchema;
use Carbon\Carbon;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

class CustomerImporter extends Importer
{
    protected static ?string $model = Customer::class;

    public static function getColumns(): array
    {
        $customerTypeValues = implode(',', CustomerType::csvImportTypeValues());
        $customerStatusValues = implode(',', array_column(CustomerStatus::cases(), 'value'));
        $paymentTermsValues = implode(',', array_column(PaymentTerms::cases(), 'value'));

        return array_merge(
            [
                ImportColumn::make('id')
                    ->label('ID')
                    ->exampleHeader('ID')
                    ->integer()
                    ->fillRecordUsing(fn (): null => null)
                    ->ignoreBlankState(),

                ImportColumn::make('type')
                    ->label('Type')
                    ->exampleHeader('Type')
                    ->requiredMapping()
                    ->castStateUsing(fn (?string $state): ?string => CustomerType::resolveCsvImportTypeValue($state))
                    ->rules(['required', 'in:' . $customerTypeValues]),

                ImportColumn::make('status')
                    ->label('Status')
                    ->exampleHeader('Status')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?string => self::resolveEnumValue($state, CustomerStatus::cases()))
                    ->rules(['nullable', 'in:' . $customerStatusValues]),

                ImportColumn::make('name')
                    ->label('Bedrijfsnaam')
                    ->exampleHeader('Bedrijfsnaam')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('salutation')
                    ->label('Aanhef')
                    ->exampleHeader('Aanhef')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('first_name')
                    ->label('Voornaam')
                    ->exampleHeader('Voornaam')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('middle_name')
                    ->label('Tussenvoegsel')
                    ->exampleHeader('Tussenvoegsel')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('last_name')
                    ->label('Achternaam')
                    ->exampleHeader('Achternaam')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('dob')
                    ->label('Geboortedatum')
                    ->exampleHeader('Geboortedatum')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?string => self::parseDate($state))
                    ->rules(['nullable', 'date']),

                ImportColumn::make('email')
                    ->label('E-mail')
                    ->exampleHeader('E-mail')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'email', 'max:255']),

                ImportColumn::make('phone_number')
                    ->label('Telefoon')
                    ->exampleHeader('Telefoon')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('mobile_phone_number')
                    ->label('Mobiel')
                    ->exampleHeader('Mobiel')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('vat')
                    ->label('BTW-nummer')
                    ->exampleHeader('BTW-nummer')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?string => ValidDutchVatNumber::normalize($state))
                    ->rules(['nullable', 'max:255', new ValidDutchVatNumber]),

                ImportColumn::make('kvk')
                    ->label('KvK')
                    ->exampleHeader('KvK')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('iban')
                    ->label('IBAN')
                    ->exampleHeader('IBAN')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('debtor_number')
                    ->label('Debiteurennummer')
                    ->exampleHeader('Debiteurennummer')
                    ->ignoreBlankState()
                    ->rules(['nullable', 'max:255']),

                ImportColumn::make('payment_terms')
                    ->label('Betalingstermijn')
                    ->exampleHeader('Betalingstermijn')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?string => self::resolveEnumValue($state, PaymentTerms::cases()))
                    ->rules(['nullable', 'in:' . $paymentTermsValues]),

                ImportColumn::make('discount_percentage')
                    ->label('Kortingspercentage (%)')
                    ->exampleHeader('Kortingspercentage (%)')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?float => self::parseDecimal($state))
                    ->rules(['nullable', 'numeric', 'min:0']),

                ImportColumn::make('delivery_address_type')
                    ->label('Leveradres-type')
                    ->exampleHeader('Leveradres-type')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?string => CustomerCsvSchema::parseDeliveryAddressType($state))
                    ->rules(['nullable', 'in:contact,custom']),

                ImportColumn::make('comment')
                    ->label('Interne opmerking')
                    ->exampleHeader('Interne opmerking')
                    ->ignoreBlankState()
                    ->rules(['nullable']),

                ImportColumn::make('newsletter_subscribed')
                    ->label('Nieuwsbrief')
                    ->exampleHeader('Nieuwsbrief')
                    ->ignoreBlankState()
                    ->castStateUsing(fn (?string $state): ?bool => self::parseBoolean($state))
                    ->rules(['nullable', 'boolean']),
            ],
            self::addressColumns('contact', 'Contactadres'),
            self::addressColumns('billing', 'Factuuradres'),
            self::addressColumns('shipping', 'Leveradres', includeLocationName: true),
        );
    }

    public function resolveRecord(): Customer
    {
        $id = $this->data['id'] ?? null;

        if (filled($id) && $existing = Customer::query()->find((int) $id)) {
            if ($existing->getType() === CustomerType::AV) {
                throw ValidationException::withMessages([
                    'id' => 'De AV-klant kan niet via CSV worden bijgewerkt.',
                ]);
            }

            return $existing;
        }

        return new Customer([
            'status' => CustomerStatus::Active->value,
        ]);
    }

    protected function beforeCreate(): void
    {
        if (blank($this->record->status)) {
            $this->record->status = CustomerStatus::Active;
        }

        $this->applyExactPaymentConditionDefault();
    }

    protected function beforeSave(): void
    {
        if ($this->record->getType() === CustomerType::AV) {
            throw ValidationException::withMessages([
                'type' => 'Het type AV kan niet via CSV worden geïmporteerd.',
            ]);
        }
    }

    protected function afterSave(): void
    {
        app(CustomerCsvAddressImporter::class)->sync($this->record, $this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'De klantenimport is voltooid. ' . $import->successful_rows . ' ' . ($import->successful_rows === 1 ? 'rij' : 'rijen') . ' geïmporteerd.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . $failedRowsCount . ' ' . ($failedRowsCount === 1 ? 'rij' : 'rijen') . ' mislukt.';
        }

        return $body;
    }

    public function getJobConnection(): ?string
    {
        return 'sync';
    }

    /**
     * @return array<int, ImportColumn>
     */
    private static function addressColumns(string $prefix, string $labelPrefix, bool $includeLocationName = false): array
    {
        $noop = fn (): null => null;

        $fieldMap = [
            'straat' => 'street',
            'huisnummer' => 'house_number',
            'toevoeging' => 'house_number_addition',
            'postcode' => 'postcode',
            'plaats' => 'city',
            'land' => 'country',
        ];

        $columns = [];

        foreach ($fieldMap as $suffix => $field) {
            $header = "{$labelPrefix} {$suffix}";

            $columns[] = ImportColumn::make("{$prefix}_{$field}")
                ->label($header)
                ->exampleHeader($header)
                ->fillRecordUsing($noop)
                ->ignoreBlankState()
                ->rules(['nullable', 'max:255']);
        }

        if ($includeLocationName) {
            $columns[] = ImportColumn::make('shipping_location_name')
                ->label('Leveradres locatienaam')
                ->exampleHeader('Leveradres locatienaam')
                ->fillRecordUsing($noop)
                ->ignoreBlankState()
                ->rules(['nullable', 'max:255']);
        }

        return $columns;
    }

    private function applyExactPaymentConditionDefault(): void
    {
        $typeKey = self::normalizedCustomerTypeKey($this->record->type);

        if ($typeKey === null) {
            return;
        }

        $conditionCode = Setting::get('exact_payment_condition_by_type.' . $typeKey);

        if (is_string($conditionCode) && $conditionCode !== '') {
            $this->record->exact_payment_condition = $conditionCode;
        }
    }

    private static function normalizedCustomerTypeKey(mixed $type): ?string
    {
        return match (true) {
            $type instanceof CustomerType => $type->value,
            $type instanceof \BackedEnum => (string) $type->value,
            is_string($type) && trim($type) !== '' => trim($type),
            default => null,
        };
    }

    /**
     * @param  array<\BackedEnum>  $cases
     */
    private static function resolveEnumValue(?string $state, array $cases): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        foreach ($cases as $case) {
            if ($case->value === $state) {
                return $case->value;
            }
        }

        foreach ($cases as $case) {
            if (method_exists($case, 'getLabel') && $case->getLabel() === $state) {
                return $case->value;
            }
        }

        return $state;
    }

    private static function parseDate(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $state = trim($state);

        foreach (['d-m-Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $state)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
        }

        return $state;
    }

    private static function parseDecimal(?string $state): ?float
    {
        if ($state === null || $state === '') {
            return null;
        }

        $normalized = str_replace(['.', ','], ['', '.'], $state);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function parseBoolean(?string $state): ?bool
    {
        if ($state === null || $state === '') {
            return null;
        }

        return match (strtolower(trim($state))) {
            'ja', 'yes', '1', 'true' => true,
            'nee', 'no', '0', 'false' => false,
            default => null,
        };
    }
}

<?php

namespace App\Services\Exact\Customers;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PaymentTerms;
use App\Models\Address;
use App\Models\Country;
use App\Models\Customer;
use App\Services\Exact\Accounts\ExactAccounts;
use App\Services\ExactOnlineService;
use Throwable;

class ExactCustomerImportService
{
    public function __construct(
        private ExactAccounts $exactAccounts,
        private ExactOnlineService $exactOnline,
    ) {}

    /**
     * Import CRM accounts reached via the customer branch of {@see \App\Console\Commands\ExactOnline\ImportAccountsFromExact}.
     *
     * @param  list<array<string, mixed>>          $accounts     Pre-fetched accounts to process.
     * @param  array<string, array<string, mixed>> $contactMap   Pre-fetched contact map keyed by account GUID.
     * @param  callable(int): void|null            $onProgressStart
     * @param  callable(): void|null               $onProgressStep
     * @return array{processed: int, created: int, updated: int, failed: int}
     */
    public function import(
        array $accounts = [],
        array $contactMap = [],
        array $bankAccountMap = [],
        ?callable $onProgressStart = null,
        ?callable $onProgressStep = null,
    ): array {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        $onProgressStart?->__invoke(count($accounts));

        foreach ($accounts as $account) {
            if (! is_array($account) || ! isset($account['ID']) || ! is_string($account['ID'])) {
                $onProgressStep?->__invoke();

                continue;
            }

            try {
                $exactId = $account['ID'];
                $existing = Customer::query()->where('exact_id', $exactId)->first();

                if ($existing?->status === CustomerStatus::Test->value) {
                    $onProgressStep?->__invoke();

                    continue;
                }

                $contact = $contactMap[$exactId] ?? null;
                $bankAccount = $bankAccountMap[$exactId] ?? null;

                $address = $this->resolveAddress($account, $existing, $contact);

                $attrs = $this->mapExactAccountToCustomerAttributes($account, $address, $contact, $bankAccount);

                Customer::query()->updateOrCreate(
                    ['exact_id' => $exactId],
                    $attrs
                );

                $stats['processed']++;
                if ($existing === null) {
                    $stats['created']++;
                } else {
                    $stats['updated']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->exactOnline->log(
                    'ExactCustomerImport: account ' . ($account['ID'] ?? '?') . ' ' . $e->getMessage(),
                    'error'
                );
            }

            $onProgressStep?->__invoke();
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>       $account
     * @param  array<string, mixed>|null  $contact
     * @param  array<string, mixed>|null  $bankAccount
     */
    private function mapExactAccountToCustomerAttributes(array $account, ?Address $address, ?array $contact, ?array $bankAccount = null): array
    {
        $code = isset($account['Code']) ? trim((string) $account['Code']) : null;
        $debtorNumber = $this->normalizeDebtorNumber($code);

        $phone = $this->nullableString($account['Phone'] ?? null);
        $ext = $this->nullableString($account['PhoneExtension'] ?? null);
        if ($phone !== null && $ext !== null) {
            $phone = $phone . ' ' . $ext;
        } elseif ($phone === null && $ext !== null) {
            $phone = $ext;
        }
        $phone = $this->normalizeDutchPhone($phone);

        $email = $this->nullableString($account['Email'] ?? null);
        $contactEmail = $this->nullableString($contact !== null ? ($contact['Email'] ?? null) : null);

        $blocked = $account['Blocked'] ?? false;
        $isActive = ! $this->toBool($blocked);

        $addressId = $address?->id;

        $accountName = trim((string) ($account['Name'] ?? ''));
        $type = $this->resolveImportedCustomerType($accountName, $email, $contactEmail);

        $contactFirstName = $this->nullableString($contact !== null ? ($contact['FirstName'] ?? null) : null);
        $contactMiddleName = $this->nullableString($contact !== null ? ($contact['MiddleName'] ?? null) : null);
        $contactLastName = $this->nullableString($contact !== null ? ($contact['LastName'] ?? null) : null);

        if ($type === CustomerType::B2C) {
            $firstName = null;
            $middleName = null;
            $lastName = null;
        } else {
            [$firstName, $middleName, $lastName] = [$contactFirstName, $contactMiddleName, $contactLastName];
        }

        $attrs = [
            'name'                    => $accountName !== '' ? $accountName : null,
            'debtor_number'           => $debtorNumber,
            'email'                   => $email,
            'phone_number'            => $phone,
            'mobile_phone_number'     => $this->nullableString($contact !== null ? ($contact['Mobile'] ?? null) : null),
            'vat'                     => $this->nullableString($account['VATNumber'] ?? null),
            'kvk'                     => $this->nullableString($account['ChamberOfCommerce'] ?? null),
            'address_id'              => $addressId,
            'billing_address_id'      => $addressId,
            'delivery_address_type'   => 'contact',
            'comment'                 => $this->nullableString($account['Remarks'] ?? null),
            'iban'                    => $this->nullableString($bankAccount !== null ? ($bankAccount['BankAccount'] ?? null) : null),
            'bic'                     => $this->nullableString($bankAccount !== null ? ($bankAccount['BICCode'] ?? null) : null),
            'discount_percentage'     => isset($account['DiscountSales']) ? (float) $account['DiscountSales'] * 100 : null,
            'exact_payment_condition' => $this->nullableString($account['PaymentConditionSales'] ?? null),
            'exact_vat_code'          => $this->nullableString($account['SalesVATCode'] ?? null),
            'type'                    => $type->value,
            'payment_terms'           => PaymentTerms::Split50_50->value,
            'exact_synced_at'         => now(),
            'status'                  => $isActive ? CustomerStatus::Active->value : CustomerStatus::Inactive->value,
        ];

        if ($type !== CustomerType::B2C) {
            $attrs['first_name'] = $firstName;
            $attrs['middle_name'] = $middleName;
            $attrs['last_name'] = $lastName;
        }

        return $attrs;
    }

    /**
     * Create or update an Address record for the imported customer.
     *
     * @param  array<string, mixed>       $account
     * @param  array<string, mixed>|null  $contact
     */
    private function resolveAddress(array $account, ?Customer $existing, ?array $contact): ?Address
    {
        $merged = $this->exactAccounts->resolvedVisitAddressFieldsForImport($account);

        $addressLine = $this->nullableString($merged['AddressLine1'] ?? null);
        $addressLine2 = $this->nullableString($merged['AddressLine2'] ?? null);
        $postcode = $this->nullableString($merged['Postcode'] ?? null);
        $city = $this->nullableString($merged['City'] ?? null);
        $countryId = $this->resolveCountryId($this->nullableString($merged['Country'] ?? null));

        $contactEmail = $this->nullableString($contact !== null ? ($contact['Email'] ?? null) : null);
        $accountDisplayName = trim((string) ($account['Name'] ?? ''));
        $accountEmail = $this->nullableString($account['Email'] ?? null);
        $importType = $this->resolveImportedCustomerType($accountDisplayName, $accountEmail, $contactEmail);

        if ($addressLine === null && $addressLine2 === null && $postcode === null && $city === null) {
            if ($accountDisplayName !== '') {
                $address = $existing?->address ?? new Address();
                $address->name = $accountDisplayName;
                if ($importType !== CustomerType::B2C) {
                    $address->email = $contactEmail;
                }
                $address->save();

                return $address;
            }

            return $existing?->address;
        }

        [$street, $houseNumber, $houseNumberAddition] = $this->splitAddress($addressLine);

        $address = $existing?->address ?? new Address();
        $address->street = $street;
        $address->house_number = $houseNumber;
        $address->house_number_addition = $houseNumberAddition;
        $address->postcode = $postcode;
        $address->city = $city;
        $address->country_id = $countryId;
        if ($importType !== CustomerType::B2C) {
            $address->email = $contactEmail;
        }

        if ($accountDisplayName !== '') {
            $address->name = $accountDisplayName;
        } elseif ($addressLine2 !== null) {
            $address->name = $addressLine2;
        } else {
            $address->name = null;
        }

        $address->save();

        return $address;
    }

    /**
     * Split "Straatnaam 123a" into [street, house_number, addition].
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function splitAddress(?string $addressLine): array
    {
        if ($addressLine === null || $addressLine === '') {
            return [null, null, null];
        }

        if (preg_match('/^(.+?)\s+(\d+)\s*([a-zA-Z0-9\-\/]*)$/', $addressLine, $matches)) {
            $street = trim($matches[1]);
            $houseNumber = $matches[2];
            $addition = trim($matches[3]);

            return [
                $street !== '' ? $street : null,
                $houseNumber,
                $addition !== '' ? $addition : null,
            ];
        }

        return [$addressLine, null, null];
    }

    private function resolveCountryId(?string $countryCode): ?int
    {
        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        $code = strtoupper(trim($countryCode));

        return Country::query()->where('code', $code)->value('id');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN)
            || $value === 1
            || $value === '1';
    }

    private function normalizeDutchPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($digits, '06') && strlen($digits) === 10) {
            return '+31' . substr($digits, 1);
        }

        return $phone;
    }

    private function normalizeDebtorNumber(mixed $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', (string) $code);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveImportedCustomerType(string $name, ?string $accountEmail, ?string $contactEmail): CustomerType
    {
        if ($this->importIndicatesBusiness($name, $accountEmail, $contactEmail)) {
            return CustomerType::B2B;
        }

        return CustomerType::B2C;
    }

    private function importIndicatesBusiness(string $name, ?string $accountEmail, ?string $contactEmail): bool
    {
        foreach ([$name, $accountEmail, $contactEmail] as $value) {
            if ($this->importFieldContainsDealerBrandKeyword($value)) {
                return true;
            }
        }

        return $this->nameHasBusinessCompanyKeywords($name);
    }

    private function importFieldContainsDealerBrandKeyword(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $lower = strtolower($value);

        foreach (['medipoint', 'meyra'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function nameHasBusinessCompanyKeywords(string $name): bool
    {
        $keywords = ['fact', 'b.v.', 'bv', 'n.v.', 'nv'];

        foreach ($keywords as $keyword) {
            $escaped = preg_quote($keyword, '/');
            if (preg_match('/(?:^|[\s(])' . $escaped . '(?:$|[\s)])/i', $name)) {
                return true;
            }
        }

        return false;
    }
}

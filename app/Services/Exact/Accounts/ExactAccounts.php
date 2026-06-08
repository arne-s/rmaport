<?php

namespace App\Services\Exact\Accounts;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Services\ExactOnlineService;
use App\Support\Exact\ExactApiErrorMessage;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

class ExactAccounts
{
    public const MAX_PAGE_SIZE = 60;

    /**
     * Exact CRM Accounts Status: A=None, C=Customer, P=Prospect, S=Suspect (Exact CRMAccounts).
     */
    public const ACCOUNTS_STATUS_FILTER_DEFAULT = "(Status eq 'C')";

    /**
     * Scope for {@see \App\Console\Commands\ExactOnline\ImportAccountsFromExact}: CRM-funnel C/P/S (zonder Status A).
     * Exact Blocked maps to inactive customers on each imported row.
     */
    public const ACCOUNTS_STATUS_FILTER_CUSTOMER_IMPORT = "(Status eq 'C' or Status eq 'P' or Status eq 'S')";

    public const ACCOUNT_SELECT = 'ID,Code,Name,AddressLine1,AddressLine2,Postcode,City,Country,Email,Phone,PhoneExtension,PaymentConditionSales,SalesVATCode,Blocked,Status,VATNumber,ChamberOfCommerce,Remarks,DiscountSales,IsSupplier,IsSales';

    public const CONTACT_SELECT = 'ID,Account,FirstName,LastName,MiddleName,Mobile,Email';

    public const BANK_ACCOUNT_SELECT = 'Account,BankAccount,BICCode';

    public function __construct(
        private ExactOnlineService $exact,
    ) {}

    /**
     * Exact CRM Accounts row marked as leverancier (`IsSupplier`).
     *
     * @param  array<string, mixed>  $account
     */
    public static function crmAccountRowIsSupplier(array $account): bool
    {
        $v = $account['IsSupplier'] ?? $account['isSupplier'] ?? null;
        if ($v === true || $v === 1) {
            return true;
        }

        if (is_string($v)) {
            return strtolower(trim($v)) === 'true';
        }

        return false;
    }

    /**
     * Leverancier-only: Exact markeert leverancier maar niet voor verkoop — geen lokale klant-sync.
     * Combinatie klant+leverancier houden we aan (`IsSales` true/null met `IsSupplier` true importeren we nog steeds).
     *
     * @param  array<string, mixed>  $account
     */
    public static function crmAccountRowIsSupplierOnly(array $account): bool
    {
        if (! self::crmAccountRowIsSupplier($account)) {
            return false;
        }

        $sales = $account['IsSales'] ?? $account['isSales'] ?? null;
        if ($sales === false || $sales === 0) {
            return true;
        }

        if (is_string($sales)) {
            $normalized = strtolower(trim($sales));

            return $normalized === 'false' || $normalized === '0';
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPage(
        int $top = self::MAX_PAGE_SIZE,
        int $skip = 0,
        ?string $odataFilter = null,
        ?string $select = null,
    ): array {
        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('ExactAccounts::fetchPage: could not obtain access token', 'error');

            return [];
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            $this->exact->log('ExactAccounts::fetchPage: no access token after refresh', 'error');

            return [];
        }

        $top = min(max(1, $top), self::MAX_PAGE_SIZE);

        $query = [
            '$top' => $top,
            '$skip' => max(0, $skip),
        ];

        if ($select !== null && $select !== '') {
            $query['$select'] = $select;
        }

        if ($odataFilter !== null && $odataFilter !== '') {
            $query['$filter'] = $odataFilter;
        }

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/Accounts');

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $raw = $response->getBody()->getContents();
            $data = json_decode($raw, true);

            return $this->normalizeAccountsPayload($data);
        } catch (RequestException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactAccounts::fetchPage: ' . $e->getMessage() . ($detail !== '' ? ' body=' . $detail : ''),
                'error'
            );

            return [];
        } catch (GuzzleException $e) {
            $this->exact->log('ExactAccounts::fetchPage: ' . $e->getMessage(), 'error');

            return [];
        } catch (Throwable $e) {
            $this->exact->log('ExactAccounts::fetchPage: ' . $e->getMessage(), 'error');

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(
        ?string $odataFilter = self::ACCOUNTS_STATUS_FILTER_DEFAULT,
        int $pageSize = self::MAX_PAGE_SIZE,
        ?string $select = null,
    ): array {
        $pageSize = min(max(1, $pageSize), self::MAX_PAGE_SIZE);
        $all = [];
        $skip = 0;

        do {
            $batch = $this->fetchPage($pageSize, $skip, $odataFilter, $select);
            $all = array_merge($all, $batch);
            $skip += count($batch);
        } while (count($batch) === $pageSize);

        return $all;
    }

    /**
     * Resolve accounts whose Exact Code equals the given code (trimmed string comparison).
     *
     * Exact OData often returns no rows for `Code eq '…'` filters even though listing accounts by status
     * returns those rows — this loads the status-filtered pages and matches Code client-side (same effective data as bulk sync).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAccountsMatchingCode(string $code, ?string $odataStatusFilter = null): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }

        $filter = $odataStatusFilter ?? self::ACCOUNTS_STATUS_FILTER_DEFAULT;
        $all = $this->fetchAll($filter, self::MAX_PAGE_SIZE, self::ACCOUNT_SELECT);

        return array_values(array_filter($all, function (array $account) use ($code): bool {
            $candidate = $account['Code'] ?? null;
            if ($candidate === null || $candidate === '') {
                return false;
            }

            return trim((string) $candidate) === $code;
        }));
    }

    /**
     * Returns the CRM main contact GUID for an account, if any.
     *
     * Documents in Exact may be linked to the account ({@see Documents/Documents Account}) or only to the
     * main contact ({@see Documents/Documents Contact}); importing both avoids missing PDFs.
     */
    public function fetchMainContactGuidForAccount(string $accountGuid): ?string
    {
        $accountGuid = trim($accountGuid);
        if ($accountGuid === '') {
            return null;
        }

        if (! $this->exact->ensureAccessTokenForApi()) {
            return null;
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return null;
        }

        $url = $this->exact->url("v1/{$this->exact->division}/crm/Accounts(guid'{$accountGuid}')");

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$select' => 'MainContact',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $row = is_array($data) ? ($data['d'] ?? $data) : null;
            if (! is_array($row)) {
                return null;
            }

            $guid = $row['MainContact'] ?? null;
            if (! is_string($guid)) {
                return null;
            }

            $guid = trim($guid, " \t\n\r\0\x0B{}");
            if ($guid === '' || strcasecmp($guid, '00000000-0000-0000-0000-000000000000') === 0) {
                return null;
            }

            return $guid;
        } catch (RequestException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactAccounts::fetchMainContactGuidForAccount: ' . $e->getMessage() . ($detail !== '' ? ' body=' . $detail : ''),
                'error'
            );
        } catch (GuzzleException $e) {
            $this->exact->log('ExactAccounts::fetchMainContactGuidForAccount: ' . $e->getMessage(), 'error');
        } catch (Throwable $e) {
            $this->exact->log('ExactAccounts::fetchMainContactGuidForAccount: ' . $e->getMessage(), 'error');
        }

        return null;
    }

    public function createAccountForCustomer(Customer $customer): ?string
    {
        $this->ensureAccessToken();

        $visit = $customer->getExactAccountVisitAddress();

        $url = $this->exact->url("v1/{$this->exact->division}/crm/Accounts");

        $addressLine1 = $visit?->getAddress();
        $addressLine1 = ($addressLine1 !== null && $addressLine1 !== '') ? $addressLine1 : null;

        $discountSales = $customer->discount_percentage !== null
            ? round((float) $customer->discount_percentage / 100, 4)
            : null;

        $body = array_filter([
            'Name'                  => $this->exactAccountNameForExact($customer),
            'AddressLine1'          => $addressLine1,
            'Postcode'              => $visit?->postcode,
            'City'                  => $visit?->city,
            'Country'               => $visit?->country?->code,
            'Status'                => 'C',
            'VATNumber'             => $customer->vat,
            'ChamberOfCommerce'     => $customer->kvk,
            'Email'                 => $customer->getEmail(),
            'Phone'                 => $customer->phone_number ?? $customer->mobile_phone_number,
            'PaymentConditionSales' => $customer->exact_payment_condition,
            'SalesVATCode'          => $customer->exact_vat_code,
            'DiscountSales'         => $discountSales,
        ], fn ($value) => $value !== null);

        $body['Remarks'] = (string) ($customer->comment ?? '');
        $body['Blocked'] = $customer->status === CustomerStatus::Inactive;

        $this->exact->log('Create customer in Exact: ', 'debug', $body);

        try {
            $response = $this->exact->client->post($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => $this->writeHeaders(),
                'body' => json_encode($body),
            ]);

            $newAccount = json_decode($response->getBody()->getContents(), true);

            $this->exact->log('New account added', 'debug', [$newAccount]);

            if (isset($newAccount['d'])) {
                return $newAccount['d']['ID'];
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $body = $response !== null ? (string) $response->getBody()->getContents() : '';
            $this->exact->log("Error occurred while creating customer: {$response?->getStatusCode()} {$body}", 'error');
            throw new RuntimeException(
                ExactApiErrorMessage::fromResponseBody($body) ?? $e->getMessage(),
                0,
                $e
            );
        } catch (Throwable $e) {
            $this->exact->log('Exact Online API error: ' . (string) $e, 'error');
            throw $e;
        }

        return null;
    }

    public function updateAccountForCustomer(Customer $customer): bool
    {
        $this->ensureAccessToken();

        if (! $customer->exact_id) {
            $this->exact->log('No Exact ID found for customer ' . $customer->id, 'error');

            return false;
        }

        $visit = $customer->getExactAccountVisitAddress();

        $url = $this->exact->url("v1/{$this->exact->division}/crm/Accounts(guid'{$customer->exact_id}')");

        $addressLine1 = $visit?->getAddress();
        $addressLine1 = ($addressLine1 !== null && $addressLine1 !== '') ? $addressLine1 : null;

        $discountSales = $customer->discount_percentage !== null
            ? round((float) $customer->discount_percentage / 100, 4)
            : null;

        $body = array_filter([
            'Name'                  => $this->exactAccountNameForExact($customer),
            'AddressLine1'          => $addressLine1,
            'Postcode'              => $visit?->postcode,
            'City'                  => $visit?->city,
            'Country'               => $visit?->country?->code,
            'VATNumber'             => $customer->vat,
            'ChamberOfCommerce'     => $customer->kvk,
            'Email'                 => $customer->getEmail(),
            'Phone'                 => $customer->phone_number ?? $customer->mobile_phone_number,
            'PaymentConditionSales' => $customer->exact_payment_condition,
            'SalesVATCode'          => $customer->exact_vat_code,
            'DiscountSales'         => $discountSales,
        ], fn ($value) => $value !== null);

        $body['Remarks'] = (string) ($customer->comment ?? '');
        $body['Blocked'] = $customer->status === CustomerStatus::Inactive;

        $this->exact->log('Updating customer in Exact: ', 'debug', $body);

        try {
            $this->exact->client->put($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => $this->writeHeaders(),
                'body' => json_encode($body),
            ]);

            $this->exact->log("Customer '{$customer->getName()}' (ID: {$customer->id}) updated successfully in Exact Online.", 'info');

            return true;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $body = $response !== null ? (string) $response->getBody()->getContents() : '';
            $this->exact->log("Error occurred while updating customer: {$response?->getStatusCode()} {$body}", 'error');
            throw new RuntimeException(
                ExactApiErrorMessage::fromResponseBody($body) ?? $e->getMessage(),
                0,
                $e
            );
        } catch (Throwable $e) {
            $this->exact->log('Exact Online API error: ' . (string) $e, 'error');
            throw $e;
        }
    }

    /**
     * Create or update the main contact person for a customer in Exact Online.
     * Fetches the existing main contact by account GUID and updates it,
     * or creates a new one when none exists.
     */
    public function updateMainContactForCustomer(Customer $customer): bool
    {
        if (! $customer->exact_id) {
            return false;
        }

        $this->ensureAccessToken();

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return false;
        }

        if ($customer->getType() === CustomerType::B2C) {
            $contactBody = array_filter([
                'LastName' => $this->trimmedOrNull($customer->name ?? null),
                'Phone'    => $this->trimmedOrNull($customer->phone_number ?? null),
                'Mobile'   => $customer->mobile_phone_number,
                'Email'    => $this->trimmedOrNull($customer->getEmail()),
            ], fn ($value) => $value !== null);
        } else {
            $billingAddress = $customer->billingAddress ?? $customer->address;
            $contactEmail = $billingAddress?->email;

            $contactBody = array_filter([
                'FirstName'  => $customer->first_name,
                'MiddleName' => $customer->middle_name,
                'LastName'   => $customer->last_name,
                'Mobile'     => $customer->mobile_phone_number,
                'Email'      => $contactEmail,
            ], fn ($value) => $value !== null);
        }

        $contactsUrl = $this->exact->url('v1/' . $this->exact->division . '/crm/Contacts');

        try {
            $response = $this->exact->client->get($contactsUrl, [
                'exact_service' => 'ExactAccounts',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => "Account eq guid'{$customer->exact_id}' and IsMainContact eq true",
                    '$select' => 'ID',
                    '$top'    => 1,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $contacts = $this->normalizeContactsPayload($data);
            $contactId = $contacts[0]['ID'] ?? null;
        } catch (Throwable $e) {
            $this->exact->log('ExactAccounts::updateMainContactForCustomer fetch: ' . $e->getMessage(), 'error');

            return false;
        }

        try {
            if ($contactId !== null) {
                $putUrl = $this->exact->url("v1/{$this->exact->division}/crm/Contacts(guid'{$contactId}')");
                $this->exact->client->put($putUrl, [
                    'exact_service' => 'ExactAccounts',
                    'headers' => $this->writeHeaders(),
                    'body' => json_encode($contactBody),
                ]);
            } else {
                $contactBody['Account'] = $customer->exact_id;
                $contactBody['IsMainContact'] = true;
                $this->exact->client->post($contactsUrl, [
                    'exact_service' => 'ExactAccounts',
                    'headers' => $this->writeHeaders(),
                    'body' => json_encode($contactBody),
                ]);
            }

            return true;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->exact->log('ExactAccounts::updateMainContactForCustomer write: ' . $response?->getStatusCode() . ' ' . $response?->getBody()?->getContents(), 'error');
        } catch (Throwable $e) {
            $this->exact->log('ExactAccounts::updateMainContactForCustomer write: ' . $e->getMessage(), 'error');
        }

        return false;
    }

    /**
     * Visit address fields for import: returns the account's own address fields directly.
     *
     * @param  array<string, mixed>  $account
     * @return array{AddressLine1: ?string, AddressLine2: ?string, Postcode: ?string, City: ?string, Country: ?string}
     */
    public function resolvedVisitAddressFieldsForImport(array $account): array
    {
        return [
            'AddressLine1' => $this->trimmedOrNull($account['AddressLine1'] ?? null),
            'AddressLine2' => $this->trimmedOrNull($account['AddressLine2'] ?? null),
            'Postcode'     => $this->trimmedOrNull($account['Postcode'] ?? null),
            'City'         => $this->trimmedOrNull($account['City'] ?? null),
            'Country'      => $this->trimmedOrNull($account['Country'] ?? null),
        ];
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /**
     * Account display name pushed to Exact CRM (Accounts.Name).
     */
    private function exactAccountNameForExact(Customer $customer): ?string
    {
        if ($customer->getType() === CustomerType::B2C) {
            $fromNameField = $this->trimmedOrNull($customer->name ?? null);
            if ($fromNameField !== null) {
                return $fromNameField;
            }
        }

        return $this->trimmedOrNull($customer->getName() ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccountByExactId(string $exactId): ?array
    {
        $this->exact->log('Fetching account data for Exact ID: ' . $exactId);

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/Accounts');
        $filter = sprintf("ID eq guid'%s' and %s", $exactId, self::ACCOUNTS_STATUS_FILTER_CUSTOMER_IMPORT);

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                    '$select' => self::ACCOUNT_SELECT,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $rows = $this->normalizeAccountsPayload($data);

            return $rows[0] ?? null;
        } catch (GuzzleException $e) {
            $this->exact->log('Failed to fetch account data by Exact ID: ' . (string) $e, 'error');
        } catch (Throwable $e) {
            $this->exact->log('Error fetching account data: ' . (string) $e, 'error');
        }

        return null;
    }

    /**
     * Whether a CRM account row still exists in Exact (any status). Used when pruning local rows after Exact deletes.
     */
    public function exactCrmAccountExists(string $exactId): bool
    {
        $exactId = trim($exactId, " \t\n\r\0\x0B{}");
        if ($exactId === '') {
            return false;
        }

        if (! $this->exact->ensureAccessTokenForApi()) {
            return true;
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return true;
        }

        $url = $this->exact->url("v1/{$this->exact->division}/crm/Accounts(guid'{$exactId}')");

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$select' => 'ID',
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (BadResponseException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($status === 404) {
                return false;
            }

            $this->exact->log('ExactAccounts::exactCrmAccountExists: ' . $e->getMessage(), 'error');

            return true;
        } catch (RequestException $e) {
            $this->exact->log('ExactAccounts::exactCrmAccountExists: ' . $e->getMessage(), 'error');

            return true;
        } catch (GuzzleException $e) {
            $this->exact->log('ExactAccounts::exactCrmAccountExists: ' . $e->getMessage(), 'error');

            return true;
        } catch (Throwable $e) {
            $this->exact->log('ExactAccounts::exactCrmAccountExists: ' . $e->getMessage(), 'error');

            return true;
        }
    }

    /**
     * OData inline total for CRM Accounts matching the OData filter (same division as fetchPage).
     */
    public function countAccountsMatchingFilter(?string $odataFilter): ?int
    {
        if (! $this->exact->ensureAccessTokenForApi()) {
            return null;
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return null;
        }

        $query = [
            '$inlinecount' => 'allpages',
            '$top' => 1,
            '$select' => 'ID',
        ];

        if ($odataFilter !== null && $odataFilter !== '') {
            $query['$filter'] = $odataFilter;
        }

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/Accounts');

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactAccounts',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data)) {
                return null;
            }

            if (isset($data['d']) && is_array($data['d']) && isset($data['d']['__count'])) {
                return max(0, (int) $data['d']['__count']);
            }

            if (isset($data['@odata.count'])) {
                return max(0, (int) $data['@odata.count']);
            }

            return null;
        } catch (RequestException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactAccounts::countAccountsMatchingFilter: ' . $e->getMessage() . ($detail !== '' ? ' body=' . $detail : ''),
                'error'
            );
        } catch (GuzzleException $e) {
            $this->exact->log('ExactAccounts::countAccountsMatchingFilter: ' . $e->getMessage(), 'error');
        } catch (Throwable $e) {
            $this->exact->log('ExactAccounts::countAccountsMatchingFilter: ' . $e->getMessage(), 'error');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeAccountsPayload(?array $data): array
    {
        if ($data === null) {
            $this->exact->log('ExactAccounts: JSON decode failed or empty body', 'error');

            return [];
        }

        if (isset($data['odata.error'])) {
            $msg = $data['odata.error']['message']['value']
                ?? $data['odata.error']['message']
                ?? json_encode($data['odata.error']);
            $this->exact->log('ExactAccounts OData error: ' . $msg, 'error');

            return [];
        }

        if (isset($data['error'])) {
            $this->exact->log('ExactAccounts API error: ' . json_encode($data['error']), 'error');

            return [];
        }

        if (isset($data['d']) && is_array($data['d'])) {
            $d = $data['d'];
            if (isset($d['results']) && is_array($d['results'])) {
                return $this->normalizeAccountRowsFromApi($d['results']);
            }
            if (array_is_list($d)) {
                return $this->normalizeAccountRowsFromApi($d);
            }
            if ($d !== []) {
                /** @var array<string, mixed> $d */
                return $this->normalizeAccountRowsFromApi([$d]);
            }
        }

        if (isset($data['value']) && is_array($data['value'])) {
            return $this->normalizeAccountRowsFromApi($data['value']);
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeAccountRowsFromApi(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->normalizeAccountRowFromApi($row);
            }
        }

        return $out;
    }

    /**
     * Map API account rows to a consistent shape (ID and ChamberOfCommerce) for the import pipeline.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAccountRowFromApi(array $row): array
    {
        $id = $row['ID'] ?? $row['Id'] ?? $row['id'] ?? null;
        if ($id !== null && $id !== '' && ! is_array($id)) {
            $row['ID'] = is_string($id) ? $id : (string) $id;
        }

        $kvk = $row['ChamberOfCommerce'] ?? null;
        $kvkEmpty = $kvk === null || (is_string($kvk) && trim($kvk) === '');
        if ($kvkEmpty) {
            $alt = $row['chamberOfCommerce'] ?? null;
            if ($alt !== null && trim((string) $alt) !== '') {
                $row['ChamberOfCommerce'] = is_string($alt) ? $alt : (string) $alt;
            }
        }

        return $row;
    }

    /**
     * Fetch main contacts for specific account GUIDs, keyed by account GUID.
     * Uses targeted OData filters in batches of 10 to avoid long filter strings.
     * Falls back to fetchAllMainContacts() when the account set is very large.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array<string, mixed>>
     */
    public function fetchMainContactsForAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $accountIds = array_values(array_filter($accountIds, fn ($id) => is_string($id) && $id !== ''));

        if ($accountIds === []) {
            return [];
        }

        if (count($accountIds) > 100) {
            return $this->fetchAllMainContacts();
        }

        if (! $this->exact->ensureAccessTokenForApi()) {
            return [];
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return [];
        }

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/Contacts');
        $map = [];

        foreach (array_chunk($accountIds, 10) as $batch) {
            $conditions = array_map(fn ($id) => "Account eq guid'{$id}'", $batch);
            $filter = '(' . implode(' or ', $conditions) . ') and IsMainContact eq true';

            try {
                $response = $this->exact->client->get($url, [
                    'exact_service' => 'ExactAccounts',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        '$filter' => $filter,
                        '$select' => self::CONTACT_SELECT,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($this->normalizeContactsPayload($data) as $contact) {
                    $accountId = $contact['Account'] ?? null;
                    if (is_string($accountId) && $accountId !== '') {
                        $map[$accountId] = $contact;
                    }
                }
            } catch (Throwable $e) {
                $this->exact->log('ExactAccounts::fetchMainContactsForAccounts: ' . $e->getMessage(), 'error');
            }
        }

        return $map;
    }

    /**
     * Fetch the main bank account for specific account GUIDs, keyed by account GUID.
     * Batches in groups of 10 to avoid long filter strings.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array<string, mixed>>
     */
    public function fetchBankAccountsForAccounts(array $accountIds): array
    {
        $accountIds = array_values(array_filter($accountIds, fn ($id) => is_string($id) && $id !== ''));

        if ($accountIds === []) {
            return [];
        }

        if (! $this->exact->ensureAccessTokenForApi()) {
            return [];
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return [];
        }

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/BankAccounts');
        $map = [];

        foreach (array_chunk($accountIds, 10) as $batch) {
            $conditions = array_map(fn ($id) => "Account eq guid'{$id}'", $batch);
            $filter = '(' . implode(' or ', $conditions) . ') and Main eq true';

            try {
                $response = $this->exact->client->get($url, [
                    'exact_service' => 'ExactAccounts',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        '$filter' => $filter,
                        '$select' => self::BANK_ACCOUNT_SELECT,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                foreach ($this->normalizeContactsPayload($data) as $bankAccount) {
                    $accountId = $bankAccount['Account'] ?? null;
                    if (is_string($accountId) && $accountId !== '') {
                        $map[$accountId] = $bankAccount;
                    }
                }
            } catch (Throwable $e) {
                $this->exact->log('ExactAccounts::fetchBankAccountsForAccounts: ' . $e->getMessage(), 'error');
            }
        }

        return $map;
    }

    /**
     * Fetch all main contacts keyed by their account GUID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchAllMainContacts(): array
    {
        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('ExactAccounts::fetchAllMainContacts: could not obtain access token', 'error');

            return [];
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return [];
        }

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/Contacts');
        $all = [];
        $skip = 0;
        $pageSize = self::MAX_PAGE_SIZE;

        do {
            try {
                $response = $this->exact->client->get($url, [
                    'exact_service' => 'ExactAccounts',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        '$top'    => $pageSize,
                        '$skip'   => $skip,
                        '$filter' => 'IsMainContact eq true',
                        '$select' => self::CONTACT_SELECT,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $rows = $this->normalizeContactsPayload($data);
                $all = array_merge($all, $rows);
                $skip += count($rows);

                if (count($rows) < $pageSize) {
                    break;
                }
            } catch (Throwable $e) {
                $this->exact->log('ExactAccounts::fetchAllMainContacts: ' . $e->getMessage(), 'error');
                break;
            }
        } while (true);

        $map = [];
        foreach ($all as $contact) {
            $accountId = $contact['Account'] ?? null;
            if (is_string($accountId) && $accountId !== '') {
                $map[$accountId] = $contact;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeContactsPayload(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        if (isset($data['d']) && is_array($data['d'])) {
            $d = $data['d'];
            if (isset($d['results']) && is_array($d['results'])) {
                return array_values(array_filter($d['results'], 'is_array'));
            }
            if (array_is_list($d)) {
                return array_values(array_filter($d, 'is_array'));
            }
        }

        if (isset($data['value']) && is_array($data['value'])) {
            return array_values(array_filter($data['value'], 'is_array'));
        }

        return [];
    }

    private function ensureAccessToken(): void
    {
        if (! $this->exact->getCurrentAccessToken()) {
            $this->exact->refreshAccessCode();
            if (! $this->exact->getCurrentAccessToken()) {
                $this->exact->log('No access token found');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function writeHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}

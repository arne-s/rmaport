<?php

namespace App\Services\Exact\Suppliers;

use App\Models\Supplier;
use App\Services\ExactOnlineService;
use App\Support\Exact\ExactApiErrorMessage;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

class ExactSuppliers
{
    /**
     * Exact Online caps many OData endpoints at 60 rows per request.
     *
     * @see https://support.exactonline.com/community/s/article/All-All-DNO-Content-dosanddonts
     */
    public const MAX_PAGE_SIZE = 60;

    /**
     * OData $select for supplier import (crm/Accounts; filter IsSupplier in $filter).
     */
    public const ACCOUNT_SUPPLIER_SELECT = 'ID,Code,Name,AddressLine1,AddressLine2,Postcode,City,Country,Email,Phone,PhoneExtension,ChamberOfCommerce,VATNumber,PaymentConditionPurchase,GLAccountPurchase,PurchaseVATCode,Blocked,SearchCode,IsSupplier,Remarks';

    public function __construct(
        private ExactOnlineService $exact,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPage(
        int $top = self::MAX_PAGE_SIZE,
        int $skip = 0,
        ?string $odataFilter = null,
        ?string $select = null,
    ): array {
        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            $this->exact->log('ExactSuppliers::fetchPage: no access token', 'error');

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

        $url = $this->exact->url('v1/'.$this->exact->division.'/crm/Accounts');
        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactSuppliers',
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
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
                'ExactSuppliers::fetchPage: '.$e->getMessage().($detail !== '' ? ' body='.$detail : ''),
                'error'
            );

            return [];
        } catch (GuzzleException $e) {
            $this->exact->log('ExactSuppliers::fetchPage: '.$e->getMessage(), 'error');

            return [];
        } catch (Throwable $e) {
            $this->exact->log('ExactSuppliers::fetchPage: '.$e->getMessage(), 'error');

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(
        ?string $odataFilter = 'IsSupplier eq true',
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
     * @param  array<string, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeAccountsPayload(?array $data): array
    {
        if ($data === null) {
            $this->exact->log('ExactSuppliers: JSON decode failed or empty body', 'error');

            return [];
        }

        if (isset($data['odata.error'])) {
            $msg = $data['odata.error']['message']['value']
                ?? $data['odata.error']['message']
                ?? json_encode($data['odata.error']);
            $this->exact->log('ExactSuppliers OData error: '.$msg, 'error');

            return [];
        }

        if (isset($data['error'])) {
            $this->exact->log('ExactSuppliers API error: '.json_encode($data['error']), 'error');

            return [];
        }

        if (isset($data['d']) && is_array($data['d'])) {
            $d = $data['d'];
            if (isset($d['results']) && is_array($d['results'])) {
                /** @var list<array<string, mixed>> */
                return $d['results'];
            }
            if (array_is_list($d)) {
                /** @var list<array<string, mixed>> */
                return $d;
            }
            if ($d !== []) {
                /** @var array<string, mixed> $d */
                return [$d];
            }
        }

        if (isset($data['value']) && is_array($data['value'])) {
            /** @var list<array<string, mixed>> */
            return $data['value'];
        }

        return [];
    }

    /**
     * Create a CRM Account as supplier in Exact Online.
     */
    public function createAccountForSupplier(Supplier $supplier): ?string
    {
        if (!$this->exact->getCurrentAccessToken()) {
            $this->exact->refreshAccessCode();
            if (!$this->exact->getCurrentAccessToken()) {
                $this->exact->log('No access token found');

                return null;
            }
        }

        $url = $this->exact->url("v1/{$this->exact->division}/crm/Accounts");

        $body = array_merge([
            'Name' => $supplier->getName(),
            'AddressLine1' => $supplier->getAddress(),
            'Postcode' => $supplier->postcode,
            'City' => $supplier->city,
            'Country' => $supplier->country->code,
            'Status' => 'A',
            'IsSupplier' => true,
            'VATNumber' => $supplier->vat_number,
            'ChamberOfCommerce' => $supplier->kvk_number,
        ], $this->purchaseFinancialFieldsForExact($supplier));

        $email = $this->supplierEmailForExact($supplier);
        if ($email !== null) {
            $body['Email'] = $email;
        }

        $this->exact->log('Create supplier in Exact: ', 'debug', $body);

        try {
            $response = $this->exact->client->post($url, [
                'exact_service' => 'ExactSuppliers',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);

            $newSupplier = json_decode($response->getBody()->getContents(), true);

            $this->exact->log('New supplier added', 'debug', [$newSupplier]);

            if (isset($newSupplier['d'])) {
                return $newSupplier['d']['ID'];
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $body = $response?->getBody()?->getContents() ?? '';
            $this->exact->log("Error occured while creating supplier: {$response?->getStatusCode()} {$body}", 'error');
            throw new RuntimeException(ExactApiErrorMessage::fromResponseBody($body) ?? $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->exact->log('Exact Online API error: ' . (string) $e, 'error');
            throw $e;
        }

        return null;
    }

    /**
     * Fetch one CRM Account by Exact GUID (supplier accounts only).
     *
     * @return array<string, mixed>|null
     */
    public function getAccountByExactId(string $exactId): ?array
    {
        $this->exact->log('Fetching supplier data for Exact ID: ' . $exactId);

        $url = $this->exact->url('v1/' . $this->exact->division . '/crm/Accounts');
        $filter = sprintf("ID eq guid'%s' and IsSupplier eq true", $exactId);

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactSuppliers',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                    '$select' => self::ACCOUNT_SUPPLIER_SELECT,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $rows = $this->normalizeAccountsPayload($data);

            return $rows[0] ?? null;
        } catch (GuzzleException $e) {
            $this->exact->log('Failed to fetch supplier data by Exact ID: ' . (string) $e, 'error');
        } catch (Throwable $e) {
            $this->exact->log('Error fetching supplier data: ' . (string) $e, 'error');
        }

        return null;
    }

    /**
     * Update an existing CRM Account (supplier) in Exact Online.
     */
    public function updateAccountForSupplier(Supplier $supplier): bool
    {
        if (!$this->exact->getCurrentAccessToken()) {
            $this->exact->refreshAccessCode();
            if (!$this->exact->getCurrentAccessToken()) {
                $this->exact->log('No access token found');

                return false;
            }
        }

        if (!$supplier->exact_id) {
            $this->exact->log('No Exact ID found for supplier ' . $supplier->id, 'error');

            return false;
        }

        $url = $this->exact->url("v1/{$this->exact->division}/crm/Accounts(guid'{$supplier->exact_id}')");

        $body = array_merge([
            'Name' => $supplier->getName(),
            'AddressLine1' => $supplier->getAddress(),
            'Postcode' => $supplier->postcode,
            'City' => $supplier->city,
            'Country' => $supplier->country->code,
            'VATNumber' => $supplier->vat_number,
            'ChamberOfCommerce' => $supplier->kvk_number,
        ], $this->purchaseFinancialFieldsForExact($supplier));

        $email = $this->supplierEmailForExact($supplier);
        if ($email !== null) {
            $body['Email'] = $email;
        }

        $this->exact->log('Updating supplier in Exact: ', 'debug', $body);

        try {
            $this->exact->client->put($url, [
                'exact_service' => 'ExactSuppliers',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);

            $this->exact->log("Supplier '{$supplier->getName()}' (ID: {$supplier->id}) updated successfully in Exact Online.", 'info');

            return true;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $body = $response?->getBody()?->getContents() ?? '';
            $this->exact->log("Error occured while updating supplier: {$response?->getStatusCode()} {$body}", 'error');
            throw new RuntimeException(ExactApiErrorMessage::fromResponseBody($body) ?? $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->exact->log('Exact Online API error: ' . (string) $e, 'error');
            throw $e;
        }

        return false;
    }

    /**
     * @return array{PaymentConditionPurchase: string|null, GLAccountPurchase: string|null, PurchaseVATCode: string|null}
     */
    private function purchaseFinancialFieldsForExact(Supplier $supplier): array
    {
        $supplier->loadMissing(['exactPaymentCondition', 'exactGlAccount', 'exactVatCode']);

        return [
            'PaymentConditionPurchase' => $supplier->exactPaymentCondition?->code,
            'GLAccountPurchase' => $supplier->exactGlAccount?->guid,
            'PurchaseVATCode' => $supplier->exactVatCode?->code,
        ];
    }

    private function supplierEmailForExact(Supplier $supplier): ?string
    {
        $raw = trim((string) ($supplier->email_supplier ?? $supplier->email ?? ''));

        return $raw === '' ? null : $raw;
    }
}


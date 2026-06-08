<?php

namespace App\Services\Exact\Products;

use App\Services\ExactOnlineService;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class ExactProducts
{
    /**
     * Exact Online caps many OData endpoints at 60 rows per request.
     *
     * @see https://support.exactonline.com/community/s/article/All-All-DNO-Content-dosanddonts
     */
    public const MAX_PAGE_SIZE = 60;

    /**
     * Max distinct Item GUIDs per logistics/SupplierItem request when using an OData `or` filter.
     * Keeps total SupplierItem rows under {@see MAX_PAGE_SIZE} in typical cases (≈1 leverancier per artikel) and reduces API calls during bulk supplier sync.
     */
    public const SUPPLIER_ITEM_BATCH_GUID_COUNT = 15;

    /**
     * OData $select for product import (logistics/Items).
     */
    public const ITEM_IMPORT_SELECT = 'ID,Code,Description,ExtraDescription,StandardSalesPrice,ItemGroup,SalesVatCode,PurchaseVATCode,Unit,UnitDescription,IsStockItem,IsFractionAllowedItem,IsPurchaseItem,IsSalesItem,IsOnDemandItem';

    public function __construct(
        private ExactOnlineService $exact,
    ) {}


    /**
     * Fetch one page of Items from Exact (logistics/Items).
     *
     * @param  string|null  $select  OData $select (comma-separated). Null omits $select so Exact returns its default field set (safest).
     * @return list<array<string, mixed>>
     */
    public function fetchPage(
        int $top = self::MAX_PAGE_SIZE,
        int $skip = 0,
        ?string $odataFilter = null,
        ?string $select = null,
    ): array {

        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('ExactProducts::fetchPage: no access token (after refresh attempt)', 'error');

            return [];
        }

        $token = $this->exact->getCurrentAccessToken();


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

        $url = $this->exact->url('v1/'.$this->exact->division.'/logistics/Items');
        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactProducts',
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $raw = $response->getBody()->getContents();
            $data = json_decode($raw, true);

            return $this->normalizeItemsPayload($data);
        } catch (RequestException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactProducts::fetchPage: '.$e->getMessage().($detail !== '' ? ' body='.$detail : ''),
                'error'
            );
            return [];
        } catch (GuzzleException $e) {
            $this->exact->log('ExactProducts::fetchPage: '.$e->getMessage(), 'error');

            return [];
        } catch (Throwable $e) {
            $this->exact->log('ExactProducts::fetchPage: '.$e->getMessage(), 'error');

            return [];
        }
    }

    /**
     * Fetch all items by paging until a page returns fewer than the page size.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(
        ?string $odataFilter = null,
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
     * SupplierItem rows for one Exact Item (Item GUID).
     *
     * Exact rejects the OData skip query parameter on this resource (error: skip not supported).
     * only one page of at most {@see MAX_PAGE_SIZE} rows can be retrieved.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchSupplierItemsPage(
        string $itemGuid,
        int $top = self::MAX_PAGE_SIZE,
    ): array {
        $top = min(max(1, $top), self::MAX_PAGE_SIZE);
        $itemGuid = trim($itemGuid);
        if ($itemGuid === '') {
            return [];
        }

        return $this->requestSupplierItemsWithFilter(
            sprintf("Item eq guid'%s'", $itemGuid),
            $top,
            false
        );
    }

    /**
     * SupplierItem rows for many Items in as few HTTP calls as possible (`Item eq guid'…' or …`).
     *
     * If a batch returns {@see MAX_PAGE_SIZE} rows, that chunk is re-fetched per Item (possible $top truncation).
     *
     * @param  list<string>  $itemGuids
     * @return list<array<string, mixed>>
     */
    public function fetchSupplierItemsPageForItemGuids(array $itemGuids): array
    {
        $guids = array_values(array_unique(array_filter(array_map(trim(...), $itemGuids))));
        if ($guids === []) {
            return [];
        }

        $merged = [];
        foreach (array_chunk($guids, self::SUPPLIER_ITEM_BATCH_GUID_COUNT) as $chunk) {
            $parts = array_map(
                fn (string $g): string => sprintf("Item eq guid'%s'", $g),
                $chunk
            );
            $filter = '('.implode(' or ', $parts).')';
            $rows = $this->requestSupplierItemsWithFilter($filter, self::MAX_PAGE_SIZE, false);
            if (count($rows) === self::MAX_PAGE_SIZE) {
                foreach ($chunk as $g) {
                    $merged = array_merge(
                        $merged,
                        $this->requestSupplierItemsWithFilter(
                            sprintf("Item eq guid'%s'", $g),
                            self::MAX_PAGE_SIZE,
                            false
                        )
                    );
                }
            } else {
                $merged = array_merge($merged, $rows);
            }
        }

        return $merged;
    }

    /**
     * One GET to logistics/SupplierItem; on 401 forces OAuth refresh and retries once; on 429 retries once (bulk supplier sync).
     *
     * @return list<array<string, mixed>>
     */
    private function requestSupplierItemsWithFilter(string $filter, int $top, bool $isRepeatAttempt): array
    {
        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('ExactProducts::requestSupplierItemsWithFilter: no access token (after refresh attempt)', 'error');

            return [];
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            $this->exact->log('ExactProducts::requestSupplierItemsWithFilter: no access token', 'error');

            return [];
        }

        $top = min(max(1, $top), self::MAX_PAGE_SIZE);
        $url = $this->exact->url('v1/'.$this->exact->division.'/logistics/SupplierItem');

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactProducts',
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                    '$top' => $top,
                ],
            ]);

            $raw = $response->getBody()->getContents();
            $data = json_decode($raw, true);

            return $this->normalizeSupplierItemsPayload($data);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            if (! $isRepeatAttempt && $status === 401) {
                if ($this->exact->forceRefreshAccessToken() !== false) {
                    return $this->requestSupplierItemsWithFilter($filter, $top, true);
                }
            }
            if (! $isRepeatAttempt && $status === 429) {
                return $this->requestSupplierItemsWithFilter($filter, $top, true);
            }

            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactProducts::requestSupplierItemsWithFilter: '.$e->getMessage().($detail !== '' ? ' body='.$detail : ''),
                'error'
            );

            return [];
        } catch (GuzzleException $e) {
            $this->exact->log('ExactProducts::requestSupplierItemsWithFilter: '.$e->getMessage(), 'error');

            return [];
        } catch (Throwable $e) {
            $this->exact->log('ExactProducts::requestSupplierItemsWithFilter: '.$e->getMessage(), 'error');

            return [];
        }
    }

    /**
     * SupplierItem rows for one Item. Exact does not support skip on this endpoint; at most {@see MAX_PAGE_SIZE} rows.
     *
     * The regular logistics/SupplierItem endpoint only returns rows with an active or future purchase price.
     * When that list is empty (e.g. expired price only), we fall back to sync/Logistics/SupplierItem so the
     * supplier link can still be resolved.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllSupplierItemsForItem(string $itemGuid): array
    {
        $itemGuid = trim($itemGuid);
        if ($itemGuid === '') {
            return [];
        }

        $rows = $this->fetchSupplierItemsPage($itemGuid, self::MAX_PAGE_SIZE);
        if ($rows !== []) {
            return $rows;
        }

        $syncRows = $this->fetchSupplierItemsPageViaSync($itemGuid);
        if ($syncRows !== []) {
            $this->exact->log(
                'ExactProducts: SupplierItem fallback via sync API for item '.$itemGuid.' ('.count($syncRows).' row(s))',
                'info'
            );
        }

        return $syncRows;
    }

    /**
     * Same shape as {@see fetchSupplierItemsPage} but uses the sync replication endpoint, which still returns
     * SupplierItem rows when the standard endpoint omits them (inactive purchase price window).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSupplierItemsPageViaSync(string $itemGuid): array
    {
        $url = $this->exact->url('v1/'.$this->exact->division.'/sync/Logistics/SupplierItem');
        $top = self::MAX_PAGE_SIZE;

        $filters = [
            sprintf("(Timestamp gt 1) and (Item eq guid'%s')", $itemGuid),
            sprintf("Item eq guid'%s'", $itemGuid),
        ];

        foreach ($filters as $index => $filter) {
            for ($authRound = 0; $authRound < 2; $authRound++) {
                try {
                    if (! $this->exact->ensureAccessTokenForApi()) {
                        $this->exact->log('ExactProducts::fetchSupplierItemsPageViaSync: no access token (after refresh attempt)', 'error');

                        return [];
                    }

                    $token = $this->exact->getCurrentAccessToken();
                    if ($token === null) {
                        return [];
                    }

                    $response = $this->exact->client->get($url, [
                        'exact_service' => 'ExactProducts',
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept' => 'application/json',
                        ],
                        'query' => [
                            '$filter' => $filter,
                            '$top' => $top,
                        ],
                    ]);

                    $raw = $response->getBody()->getContents();
                    $data = json_decode($raw, true);
                    $rows = $this->normalizeSupplierItemsPayload($data);
                    if ($rows !== []) {
                        return $rows;
                    }

                    if (isset($filters[$index + 1])) {
                        continue 2;
                    }

                    return [];
                } catch (RequestException $e) {
                    $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                    $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
                    if ($status === 401 && $authRound === 0 && $this->exact->forceRefreshAccessToken() !== false) {
                        continue;
                    }
                    if ($status === 429 && $authRound === 0) {
                        continue;
                    }
                    if ($status === 400 && isset($filters[$index + 1])) {
                        continue 2;
                    }
                    $this->exact->log(
                        'ExactProducts::fetchSupplierItemsPageViaSync: '.$e->getMessage().($detail !== '' ? ' body='.$detail : ''),
                        'error'
                    );

                    return [];
                } catch (GuzzleException $e) {
                    $this->exact->log('ExactProducts::fetchSupplierItemsPageViaSync: '.$e->getMessage(), 'error');

                    return [];
                } catch (Throwable $e) {
                    $this->exact->log('ExactProducts::fetchSupplierItemsPageViaSync: '.$e->getMessage(), 'error');

                    return [];
                }
            }
        }

        return [];
    }

    /**
     * @deprecated Use {@see fetchAllSupplierItemsForItem}.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchSupplierItemsForItem(string $itemGuid): array
    {
        return $this->fetchAllSupplierItemsForItem($itemGuid);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeSupplierItemsPayload(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        if (isset($data['odata.error'])) {
            $msg = $data['odata.error']['message']['value']
                ?? $data['odata.error']['message']
                ?? json_encode($data['odata.error']);
            $this->exact->log('ExactProducts SupplierItem OData error: '.$msg, 'error');

            return [];
        }

        if (isset($data['error'])) {
            $msg = $data['error']['message']['value']
                ?? $data['error']['message']
                ?? json_encode($data['error']);
            $this->exact->log('ExactProducts SupplierItem API error: '.$msg, 'error');

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
     * @param  array<string, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeItemsPayload(?array $data): array
    {
        if ($data === null) {
            $this->exact->log('ExactProducts: JSON decode failed or empty body', 'error');
            return [];
        }

        if (isset($data['odata.error'])) {
            $msg = $data['odata.error']['message']['value']
                ?? $data['odata.error']['message']
                ?? json_encode($data['odata.error']);
            $this->exact->log('ExactProducts OData error: '.$msg, 'error');
            return [];
        }

        if (isset($data['error'])) {
            $this->exact->log('ExactProducts API error: '.json_encode($data['error']), 'error');
            return [];
        }

        if (isset($data['d']) && is_array($data['d'])) {
            $d = $data['d'];

            // Classic OData: { "d": { "results": [ ... ] } }
            if (isset($d['results']) && is_array($d['results'])) {
                /** @var list<array<string, mixed>> */
                return $d['results'];
            }

            // Some Exact responses: { "d": [ { item }, { item } ] }
            if (array_is_list($d)) {
                /** @var list<array<string, mixed>> */
                return $d;
            }

            // Single entity: { "d": { "ID": "...", "Code": "..." } }
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
     * OData $filter fragment for logistics/Items on article Code.
     *
     * Exact stores Item.Code padded with trailing spaces (fixed width). `Code eq 'RDOR.X'` then returns no rows because the
     * stored value is e.g. `'RDOR.X      …'`. Prefer `startswith(Code, '…')` (or filter client-side after a broader query).
     */
    public static function odataFilterItemCodeStartsWith(string $code): string
    {
        $literal = str_replace("'", "''", trim($code));

        return "startswith(Code, '".$literal."')";
    }

    /**
     * Escape a string for use inside OData single-quoted literals.
     */
    private function escapeODataString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}

<?php

namespace App\Services\Exact\Documents;

use App\Services\Exact\Accounts\ExactAccounts;
use App\Services\ExactOnlineService;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class ExactDocuments
{
    public const MAX_PAGE_SIZE = 60;

    /** OData $select fields fetched from documents/Documents. */
    public const DOCUMENT_SELECT = 'ID,Type,TypeDescription,Subject,DocumentDate,Account';

    public function __construct(
        private ExactOnlineService $exact,
        private ExactAccounts $exactAccounts,
    ) {}

    /**
     * Fetch all documents belonging to a given Exact account GUID.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchForAccount(string $accountGuid): array
    {
        $filter = sprintf("Account eq guid'%s'", $accountGuid);
        $mainContactGuid = $this->exactAccounts->fetchMainContactGuidForAccount($accountGuid);
        if ($mainContactGuid !== null) {
            $filter = sprintf("(Account eq guid'%s') or (Contact eq guid'%s')", $accountGuid, $mainContactGuid);
        }

        $all = [];
        $skip = 0;

        do {
            $batch = $this->fetchPage(
                top: self::MAX_PAGE_SIZE,
                skip: $skip,
                odataFilter: $filter,
                select: self::DOCUMENT_SELECT,
            );
            $all = array_merge($all, $batch);
            $skip += count($batch);
        } while (count($batch) === self::MAX_PAGE_SIZE);

        return $this->dedupeDocumentsByExactId($all);
    }

    /**
     * Download the first PDF attachment for a document and return the raw bytes.
     *
     * Exact Online does NOT return attachment binary content in list responses.
     * Each attachment has a `Url` field (SysAttachment.aspx) that must be fetched
     * separately with the Bearer token to retrieve the actual file bytes.
     *
     * Returns null when no attachment is found or the download fails.
     */
    public function downloadPdf(string $documentId): ?string
    {
        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('ExactDocuments::downloadPdf: no access token', 'error');

            return null;
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            return null;
        }

        $listUrl = $this->exact->url("v1/{$this->exact->division}/documents/DocumentAttachments");

        try {
            $response = $this->exact->client->get($listUrl, [
                'exact_service' => 'ExactDocuments',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => sprintf("Document eq guid'%s'", $documentId),
                    '$select' => 'ID,FileName,Url',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $results = $this->normalizeResults($data);

            /** @var list<array<string, mixed>> $sorted */
            $sorted = array_values(array_filter($results, 'is_array'));
            usort($sorted, function (array $a, array $b): int {
                $aPdf = str_ends_with(mb_strtolower((string) ($a['FileName'] ?? '')), '.pdf');
                $bPdf = str_ends_with(mb_strtolower((string) ($b['FileName'] ?? '')), '.pdf');

                return ($aPdf ? 0 : 1) <=> ($bPdf ? 0 : 1);
            });

            foreach ($sorted as $attachment) {
                $downloadUrl = (string) ($attachment['Url'] ?? '');

                if ($downloadUrl === '') {
                    continue;
                }

                $download = $this->downloadFromUrlWithContentType($downloadUrl, $token);
                if ($download === null) {
                    continue;
                }

                if ($this->responseLooksLikePdf($download['bytes'], $download['content_type'])) {
                    return $download['bytes'];
                }
            }
        } catch (RequestException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactDocuments::downloadPdf: ' . $e->getMessage() . ($detail !== '' ? ' body=' . $detail : ''),
                'error'
            );
        } catch (GuzzleException $e) {
            $this->exact->log('ExactDocuments::downloadPdf: ' . $e->getMessage(), 'error');
        } catch (Throwable $e) {
            $this->exact->log('ExactDocuments::downloadPdf: ' . $e->getMessage(), 'error');
        }

        return null;
    }

    /**
     * @return array{bytes: string, content_type: string}|null
     */
    private function downloadFromUrlWithContentType(string $url, string $token): ?array
    {
        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactDocuments',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            $bytes = $response->getBody()->getContents();

            if ($bytes === '' || $bytes === false) {
                return null;
            }

            return [
                'bytes' => $bytes,
                'content_type' => $response->getHeaderLine('Content-Type'),
            ];
        } catch (GuzzleException $e) {
            $this->exact->log('ExactDocuments::downloadFromUrlWithContentType: ' . $e->getMessage(), 'error');
        } catch (Throwable $e) {
            $this->exact->log('ExactDocuments::downloadFromUrlWithContentType: ' . $e->getMessage(), 'error');
        }

        return null;
    }

    private function responseLooksLikePdf(string $bytes, string $contentType): bool
    {
        if (str_starts_with($bytes, '%PDF')) {
            return true;
        }

        return str_contains(strtolower($contentType), 'application/pdf');
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function dedupeDocumentsByExactId(array $documents): array
    {
        $byId = [];
        foreach ($documents as $doc) {
            $id = $doc['ID'] ?? $doc['Id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $id = trim($id, " \t\n\r\0\x0B{}");
            if ($id === '') {
                continue;
            }

            $byId[$id] = $doc;
        }

        return array_values($byId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPage(
        int $top = self::MAX_PAGE_SIZE,
        int $skip = 0,
        ?string $odataFilter = null,
        ?string $select = null,
        ?string $orderby = 'ID',
    ): array {
        if (! $this->exact->ensureAccessTokenForApi()) {
            $this->exact->log('ExactDocuments::fetchPage: no access token', 'error');

            return [];
        }

        $token = $this->exact->getCurrentAccessToken();
        if ($token === null) {
            $this->exact->log('ExactDocuments::fetchPage: no access token after refresh', 'error');

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

        if ($orderby !== null && $orderby !== '') {
            $query['$orderby'] = $orderby;
        }

        $url = $this->exact->url("v1/{$this->exact->division}/documents/Documents");

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactDocuments',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->normalizeResults($data);
        } catch (BadResponseException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactDocuments::fetchPage: ' . $e->getMessage() . ($detail !== '' ? ' body=' . $detail : ''),
                'error'
            );
        } catch (RequestException $e) {
            $detail = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $this->exact->log(
                'ExactDocuments::fetchPage: ' . $e->getMessage() . ($detail !== '' ? ' body=' . $detail : ''),
                'error'
            );
        } catch (GuzzleException $e) {
            $this->exact->log('ExactDocuments::fetchPage: ' . $e->getMessage(), 'error');
        } catch (Throwable $e) {
            $this->exact->log('ExactDocuments::fetchPage: ' . $e->getMessage(), 'error');
        }

        return [];
    }

    /**
     * Normalize the OData JSON envelope (d.results or value) into a flat list.
     *
     * @param  array<string, mixed>|null  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeResults(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        if (isset($data['odata.error'])) {
            $msg = $data['odata.error']['message']['value']
                ?? $data['odata.error']['message']
                ?? json_encode($data['odata.error']);
            $this->exact->log('ExactDocuments OData error: ' . $msg, 'error');

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
            if ($d !== []) {
                /** @var array<string, mixed> $d */
                return [$d];
            }
        }

        if (isset($data['value']) && is_array($data['value'])) {
            return array_values(array_filter($data['value'], 'is_array'));
        }

        return [];
    }
}

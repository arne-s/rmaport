<?php

namespace App\Services\Exact\Invoices;

use App\Services\ExactOnlineService;
use GuzzleHttp\Exception\BadResponseException;
use Throwable;

/**
 * @deprecated Sales invoices are now submitted as SalesEntries via ExactSalesEntry.
 * This class is retained only for the getPaidSalesInvoices() receivables check.
 */
class ExactInvoice
{
    public function __construct(
        private ExactOnlineService $exact,
    ) {}

    /**
     * Fetch paid sales invoices from Exact Online receivables.
     *
     * Matches on the Description field which is set to the invoice UID when submitting
     * the SalesEntry (see ExactSalesEntry::submitSalesEntry).
     *
     * @param  list<string>  $uids  Invoice UIDs (orders.uid).
     * @return list<array<string, mixed>>|null
     */
    public function getPaidSalesInvoices(array $uids): ?array
    {
        if (empty($uids)) {
            return [];
        }

        if (! $this->exact->ensureAccessTokenForApi()) {
            return null;
        }

        $selectFields = 'EntryID,AccountName,AmountDC,Description,IsFullyPaid,Status,LastPaymentDate,EntryDate';
        $filter = '(IsFullyPaid eq true or Status eq 50)';

        $idFilters = array_map(fn (string $uid) => "Description eq '{$uid}'", $uids);
        $filter .= ' and (' . implode(' or ', $idFilters) . ')';

        $url = $this->exact->url("v1/{$this->exact->division}/cashflow/Receivables?\$select={$selectFields}&\$filter={$filter}");

        try {
            $response = $this->exact->client->get($url, [
                'exact_service' => 'ExactInvoice',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->exact->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['d']['results'] ?? [];
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->exact->log(
                "Error fetching paid sales invoices: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}",
                'error'
            );
        } catch (Throwable $e) {
            $this->exact->log('Exact Online API error: ' . $e->getMessage(), 'error');
        }

        return null;
    }
}

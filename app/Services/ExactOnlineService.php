<?php

namespace App\Services;

use App\Models\Country;
use App\Enums\ArticleGroupGlAccountType;
use App\Models\ArticleGroupGlAccount;
use App\Models\ExactArticleGroup;
use App\Models\ExactGLAccount;
use App\Models\ExactPaymentCondition;
use App\Models\ExactToken;
use App\Models\ExactVATCode;
use App\Models\Order\BaseOrder;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Customer;
use App\Support\Exact\ExactApiErrorMessage;
use App\Services\Exact\Accounts\ExactAccounts;
use App\Services\Exact\Products\ExactProducts;
use App\Services\Exact\Suppliers\ExactSuppliers;
use App\Support\Exact\ExactRequestMiddleware;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;
use Throwable;

class ExactOnlineService
{
    const UNITS = [
        // Pieces
        'pc' => [
            'ID' => '7ddf0c49-cc0f-4641-bc0e-03fefe897926',
            'Code' => 'pc      ',
        ],
    ];

    public ?string $clientId = null;
    public string $clientSecret;
    public string $redirectUri;
    public string $division;
    public string $baseUrl = 'https://start.exactonline.nl/api/';
    public bool $testmode = false;

    public Client $client;

    public function __construct()
    {
        // Initialize the Guzzle Client with default headers
        if (config('exact.enabled')) {
            $handlerStack = HandlerStack::create();
            $handlerStack->push(ExactRequestMiddleware::create());
            $this->client = new Client([
                'handler' => $handlerStack,
            ]);
        } else {
            return;
        }
        $this->testmode = (bool)config('exact.testmode');
        $this->clientId = config('exact.client_id');
        $this->clientSecret = config('exact.client_secret');
        $this->redirectUri = config('exact.redirect_uri');
        $this->division = config('exact.division');
    }

    public function getCurrentAccessToken(): ?string
    {
        /* @var ExactToken $token */
        try {
            return ExactToken::where('expires_at', '>', now())
                ->orderBy('created_at', 'desc')
                ->first()?->getAccessToken();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Ensure a usable OAuth access token before REST calls (e.g. artisan imports).
     * Refreshes when the current token is missing or expired, matching other ExactOnlineService callers.
     */
    public function ensureAccessTokenForApi(): bool
    {
        if ($this->getCurrentAccessToken() !== null) {
            return true;
        }

        $refreshed = $this->refreshAccessCode();

        return $refreshed !== false && $this->getCurrentAccessToken() !== null;
    }

    public function url(string $path): string
    {
        $this->log('REQUEST: ' . $path);
        return $this->baseUrl . $path;
    }

    public function getAuthorizationUrl(): string
    {
        $query = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'force_login' => 'true',
            'time' => time(),
        ];

        return $this->url('oauth2/auth') . '?' . http_build_query($query);
    }

    /**
     */
    public function saveAccessToken(string $code): bool
    {
        $query = [
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        try {
            $response = json_decode($this->client->post($this->url('oauth2/token'), [
                'form_params' => $query,
            ])->getBody()->getContents(), true);

            ExactToken::truncate();

            $exactToken = new ExactToken();
            $exactToken->setAccessToken($response['access_token']);
            $exactToken->setRefreshToken($response['refresh_token']);
            $exactToken->setExpiresAt(now()->addSeconds((int)$response['expires_in']));

            return $exactToken->save();

        } catch (GuzzleException $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }


    /**
     * Log a message with a specified type.
     *
     * @param string $message The message to log.
     * @param string $type The type of log ('debug', 'error', 'info', etc.).
     */
    public function log(string $message, string $type = 'debug', array $context = []): void
    {
        Log::driver('exact-online')->$type($message, $context);
    }

    public function refreshAccessCode(): ExactToken|bool
    {
        /* @var ExactToken|null $exactToken */
        $exactToken = ExactToken::query()
            ->where('expires_at', '<=', now())
            ->orderByDesc('created_at')
            ->first();

        if (empty($exactToken)) {
            // $this->log('Token not expired');
            return false;
        }

        $this->log('Token expired, refreshing token');

        return $this->performOAuthRefresh($exactToken);
    }

    /**
     * Refresh the access token using the latest stored row (even if expires_at is still in the future).
     * Use when Exact returns HTTP 401 while the DB still considers the token valid (clock skew, revocation, etc.).
     */
    public function forceRefreshAccessToken(): ExactToken|false
    {
        $exactToken = ExactToken::query()
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->orderByDesc('created_at')
            ->first();

        if ($exactToken === null) {
            return false;
        }

        $this->log('Exact access token: forcing OAuth refresh after API rejected the current token');

        return $this->performOAuthRefresh($exactToken);
    }

    /**
     * Exchange refresh_token for a new access token and persist on the given row.
     */
    private function performOAuthRefresh(ExactToken $exactToken): ExactToken|false
    {
        $query = [
            'refresh_token' => $exactToken->getRefreshToken(),
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        try {
            $response = json_decode($this->client->post($this->url('oauth2/token'), [
                'form_params' => $query,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
            ])->getBody()->getContents() ?? '', true);
        } catch (GuzzleException $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        } catch (Throwable $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }

        if (empty($response['access_token'])) {
            $this->log('No Exact access token in response, continuing', 'error');

            return false;
        }

        $exactToken->setAccessToken($response['access_token']);
        $exactToken->setRefreshToken($response['refresh_token']);
        $exactToken->setExpiresAt(now()->addSeconds((int) $response['expires_in']));
        $exactToken->save();

        return $exactToken;
    }

    public function isConnected($fuzzy = false): bool
    {
        return ExactToken::where('expires_at', '>', now()->subMinutes($fuzzy ? 10 : 2))->count() > 0;

        $exactToken = $this->getCurrentAccessToken();
        if (!$exactToken) {
            return false;
        }

        if (!isset($this->client)) {
            return false;
        }
        try {
            $response = $this->client->get($this->url('v1/current/Me?$select=CurrentDivision'), [
                'headers' => ['Authorization' => 'Bearer ' . $this->getCurrentAccessToken()]
            ]);
            //$this->log('isConnected: ' . ($success ? 'true' : 'false'));

            return ($response->getStatusCode() === 200);

        } catch (GuzzleException $e) {
            $this->log($e->getMessage(), 'error');
            $this->log('isConnected: false');
            return false;
        }
    }

    public function deleteResource(string $endpoint, string $entryId): bool
    {
        if (empty($endpoint) || empty($entryId)) {
            $this->log('Endpoint or entry ID is empty', 'error');
            return false;
        }
        $url = $this->url("v1/{$this->division}/{$endpoint}(guid'{$entryId}')");

        try {
            $response = $this->client->delete($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 204) {
                $this->log("{$endpoint} with ID {$entryId} deleted successfully.", 'info');
                return true;
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error deleting {$endpoint} with ID {$entryId} in Exact Online: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log('Exact Online API error: ' . (string)$e, 'error');
        }
        return false;
    }

    /**
     * @deprecated No longer used. Invoice numbers are generated locally (F-YYYY-NNNN).
     */
    public function getLatestInvoiceNumber(): int|false
    {
        return false;
    }

    public function getItemGroupsFromExact(): array
    {
        $url = $this->url("v1/{$this->division}/logistics/ItemGroups");

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['d']['results'] ?? [];
        } catch (GuzzleException $e) {
            $this->log('Error fetching article groups: ' . $e->getMessage(), 'error');
            return [];
        }
    }


    /**
     * @deprecated Use ExactSalesEntry::submitSalesEntry() instead.
     */
    public function submitInvoice(BaseOrder $invoice, bool $mailCustomer = false): false|BaseOrder
    {
        $result = (new \App\Services\Exact\Invoices\ExactSalesEntry($this))->submitSalesEntry($invoice);

        return $result === null ? false : $result['invoice'];
    }

    /**
     * @deprecated SalesEntries do not require a print/process step.
     */
    public function processInvoice(BaseOrder $invoice): bool
    {
        return true;
    }

    /**
     * @deprecated Use ExactSalesEntry::deleteSalesEntry() instead.
     */
    public function deleteInvoice(BaseOrder $invoice): bool
    {
        return (new \App\Services\Exact\Invoices\ExactSalesEntry($this))->deleteSalesEntry($invoice);
    }

    /**
     * Syncs item groups and their corresponding GL accounts from Exact Online to the local database.
     *
     * @return bool
     */
    public function syncArticleGroups(): bool
    {
        $url = $this->url("v1/{$this->division}/logistics/ItemGroups");

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['d']['results'])) {
                foreach ($data['d']['results'] as $articleGroup) {
                    $guid = $articleGroup['ID'];
                    $name = $articleGroup['Code'] . ' : ' . $articleGroup['Description'];
                    $vatCode = 10;

                    $itemGroup = ExactArticleGroup::query()->updateOrCreate(
                        ['guid' => $guid],
                        ['name' => $name, 'vat_code' => $vatCode],
                    );

                    $this->syncArticleGroupGlAccounts($itemGroup, $articleGroup);
                }

                $this->log('Item groups synced successfully.', 'info');
                return true;
            }
        } catch (GuzzleException $e) {
            $this->log("Error syncing item groups: " . $e->getMessage(), 'error');
            return false;
        }

        $this->log('No item groups found in Exact Online.', 'info');
        return false;
    }

    private function syncArticleGroupGlAccounts(ExactArticleGroup $itemGroup, array $apiRow): void
    {
        foreach (ArticleGroupGlAccountType::cases() as $type) {
            $fields = $type->exactApiFields();
            $glGuid = $apiRow[$fields['guid']] ?? null;

            if (empty($glGuid)) {
                ArticleGroupGlAccount::query()
                    ->where('exact_article_group_id', $itemGroup->id)
                    ->where('type', $type)
                    ->delete();
                continue;
            }

            $glAccount = ExactGLAccount::query()->where('guid', $glGuid)->first();

            if ($glAccount === null) {
                $this->log(
                    "syncArticleGroups: GL account {$glGuid} not found locally for type {$type->value} on group {$itemGroup->guid}",
                    'warning',
                );
                continue;
            }

            ArticleGroupGlAccount::query()->updateOrCreate(
                [
                    'exact_article_group_id' => $itemGroup->id,
                    'type' => $type,
                ],
                [
                    'exact_gl_account_id' => $glAccount->id,
                ],
            );
        }
    }

    /**
     * @deprecated Document upload is now handled by ExactSalesEntry::uploadPdfAndCreateDocument().
     */
    public function sendInvoiceDocument(BaseOrder $invoice): string|false
    {
        return (new \App\Services\Exact\Invoices\ExactSalesEntry($this))->uploadPdfAndCreateDocument($invoice) ?: false;
    }

    /**
     * Build the Exact Online Items API payload from a Product.
     *
     * @return array<string, mixed>
     */
    private function buildProductExactPayload(Product $product): array
    {
        $body = [
            'Code' => $product->getExactCode(),
            'Description' => trim($product->getName()),
            'ExtraDescription' => $product->comment ?? '',
            'IsStockItem' => (bool) $product->is_stock_enabled,
            'IsFractionAllowedItem' => (bool) $product->is_fraction_allowed_item,
            'IsPurchaseItem' => (bool) $product->is_purchase_item,
            'IsSalesItem' => (bool) $product->is_sales_item,
            'IsOnDemandItem' => (bool) $product->is_on_demand_item,
        ];

        if ($product->exactArticleGroup) {
            $body['ItemGroup'] = $product->exactArticleGroup->getGuid();
        }

        if ($product->exactSalesVatCode) {
            // Exact Items API: SalesVatCode is Edm.String (VAT code), not a GUID.
            $salesVatCode = trim((string) ($product->exactSalesVatCode->code ?? ''));
            if ($salesVatCode !== '') {
                $body['SalesVatCode'] = $salesVatCode;
            }
        }

        if ($product->company_purchase_price !== null) {
            $body['CostPriceStandard'] = (float) $product->company_purchase_price;
        }

        if ($product->company_sales_price !== null) {
            $body['StandardSalesPrice'] = (float) $product->company_sales_price;
        }

        return $body;
    }

    /**
     * Push purchase and sales prices to Exact after the Item record exists.
     * Items.StandardSalesPrice is not reliably writable via PUT; sales prices use logistics/SalesItemPrices.
     */
    private function syncProductPricesToExact(Product $product): void
    {
        if (! $product->getExactId()) {
            return;
        }

        $this->syncSupplierItemPurchasePriceForProduct($product);
        $this->syncSalesItemPriceForProduct($product);
    }

    private function syncSupplierItemPurchasePriceForProduct(Product $product): void
    {
        $purchasePrice = (float) ($product->company_purchase_price ?? 0);
        $exactItemId = (string) $product->getExactId();

        $supplierItemId = $product->getExactSupplierItemId();
        if ($supplierItemId) {
            $this->updateSupplierItemPurchasePrice($supplierItemId, $purchasePrice);

            return;
        }

        $this->linkSupplierToProduct($product, $exactItemId);
    }

    private function updateSupplierItemPurchasePrice(string $supplierItemId, float $purchasePrice): bool
    {
        $url = $this->url("v1/{$this->division}/logistics/SupplierItem(guid'{$supplierItemId}')");

        try {
            $response = $this->client->put($url, [
                'exact_service' => 'ExactOnlineService',
                'headers' => $this->exactJsonRequestHeaders(),
                'body' => json_encode(['PurchasePrice' => $purchasePrice]),
            ]);

            return in_array($response->getStatusCode(), [200, 204], true);
        } catch (BadResponseException $e) {
            $this->log(
                "Error updating SupplierItem purchase price in Exact Online: {$e->getResponse()?->getStatusCode()} {$e->getResponse()?->getBody()?->getContents()}",
                'error'
            );
        } catch (Throwable $e) {
            $this->log('Error updating SupplierItem purchase price in Exact Online: '.$e->getMessage(), 'error');
        }

        return false;
    }

    private function syncSalesItemPriceForProduct(Product $product): void
    {
        $salesPrice = (float) ($product->company_sales_price ?? 0);
        $exactItemId = (string) $product->getExactId();

        if ($salesPrice <= 0) {
            return;
        }

        $existingPriceRow = $this->findDefaultSalesItemPriceForProduct($exactItemId);

        if ($existingPriceRow !== null && filled($existingPriceRow['ID'] ?? null)) {
            $this->updateSalesItemPrice((string) $existingPriceRow['ID'], $salesPrice);

            return;
        }

        $this->createSalesItemPrice($exactItemId, $salesPrice);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDefaultSalesItemPriceForProduct(string $exactItemId): ?array
    {
        $rows = $this->getSalesItemPricesForProduct($exactItemId);

        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if (empty($row['Account'] ?? null)) {
                return $row;
            }
        }

        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getSalesItemPricesForProduct(string $exactItemId): array
    {
        $url = $this->url("v1/{$this->division}/logistics/SalesItemPrices");
        $filter = sprintf("Item eq guid'%s'", $exactItemId);

        try {
            $response = $this->client->get($url, [
                'exact_service' => 'ExactOnlineService',
                'headers' => $this->exactJsonRequestHeaders(),
                'query' => [
                    '$filter' => $filter,
                    '$select' => 'ID,Item,Account,Price,Quantity,Unit',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['d']['results'] ?? [];
        } catch (BadResponseException $e) {
            $this->log(
                "Error fetching sales item prices in Exact Online: {$e->getResponse()?->getStatusCode()} {$e->getResponse()?->getBody()?->getContents()}",
                'error'
            );
        } catch (Throwable $e) {
            $this->log('Error fetching sales item prices in Exact Online: '.$e->getMessage(), 'error');
        }

        return [];
    }

    private function updateSalesItemPrice(string $salesItemPriceId, float $salesPrice): bool
    {
        $url = $this->url("v1/{$this->division}/logistics/SalesItemPrices(guid'{$salesItemPriceId}')");

        try {
            $response = $this->client->put($url, [
                'exact_service' => 'ExactOnlineService',
                'headers' => $this->exactJsonRequestHeaders(),
                'body' => json_encode(['Price' => $salesPrice]),
            ]);

            return in_array($response->getStatusCode(), [200, 204], true);
        } catch (BadResponseException $e) {
            $this->log(
                "Error updating SalesItemPrice in Exact Online: {$e->getResponse()?->getStatusCode()} {$e->getResponse()?->getBody()?->getContents()}",
                'error'
            );
        } catch (Throwable $e) {
            $this->log('Error updating SalesItemPrice in Exact Online: '.$e->getMessage(), 'error');
        }

        return false;
    }

    private function createSalesItemPrice(string $exactItemId, float $salesPrice): bool
    {
        $url = $this->url("v1/{$this->division}/logistics/SalesItemPrices");

        $body = [
            'Item' => $exactItemId,
            'Price' => $salesPrice,
            'Quantity' => 1,
            'Unit' => self::UNITS['pc']['Code'],
        ];

        try {
            $response = $this->client->post($url, [
                'exact_service' => 'ExactOnlineService',
                'headers' => $this->exactJsonRequestHeaders(),
                'body' => json_encode($body),
            ]);

            return in_array($response->getStatusCode(), [200, 201, 204], true);
        } catch (BadResponseException $e) {
            $this->log(
                "Error creating SalesItemPrice in Exact Online: {$e->getResponse()?->getStatusCode()} {$e->getResponse()?->getBody()?->getContents()}",
                'error'
            );
        } catch (Throwable $e) {
            $this->log('Error creating SalesItemPrice in Exact Online: '.$e->getMessage(), 'error');
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function exactJsonRequestHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->requireAccessTokenForApi(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @throws RuntimeException When no usable OAuth access token is available.
     */
    private function requireAccessTokenForApi(): string
    {
        if (! $this->ensureAccessTokenForApi()) {
            throw new RuntimeException(ExactApiErrorMessage::EXACT_RECONNECT_MESSAGE);
        }

        $token = $this->getCurrentAccessToken();
        if ($token === null || $token === '') {
            throw new RuntimeException(ExactApiErrorMessage::EXACT_RECONNECT_MESSAGE);
        }

        return $token;
    }

    /**
     * @throws RuntimeException
     */
    private function throwProductExactApiException(BadResponseException $exception): never
    {
        $response = $exception->getResponse();
        $status = $response?->getStatusCode();
        $rawBody = $response !== null ? (string) $response->getBody() : '';
        $message = ExactApiErrorMessage::fromResponseBody($rawBody)
            ?? ExactApiErrorMessage::fromResponse($response)
            ?? $exception->getMessage();

        if (str_contains($message, 'Geblokkeerd') && str_contains($message, 'Grootboekrekening')) {
            $message .= ' Wijzig de omzetrekening op de artikelgroep in Exact Online of deblokkeer de rekening, en synchroniseer artikelgroepen opnieuw.';
        }

        $this->log(
            'Exact product API error: '.($status ?? 'unknown').' '.$message.($rawBody !== '' ? ' | body: '.$rawBody : ''),
            'error'
        );

        throw new RuntimeException($message, 0, $exception);
    }

    /**
     * Create a new product in Exact Online.
     *
     * @return string|null Returns the GUID of the newly created product if successful, otherwise null.
     */
    public function createProductInExact(Product $product): ?string
    {
        return $this->createProductInExactWithAuthRetry($product, false);
    }

    private function createProductInExactWithAuthRetry(Product $product, bool $isRepeatAttempt): ?string
    {
        $url = $this->url('v1/'.$this->division.'/logistics/Items');
        $body = $this->buildProductExactPayload($product);

        $this->log('Create product in Exact: ', 'debug', $body);

        try {
            $response = $this->client->post($url, [
                'exact_service' => 'ExactOnlineService',
                'headers' => $this->exactJsonRequestHeaders(),
                'body' => json_encode($body),
            ]);

            $newProduct = json_decode($response->getBody()->getContents(), true);

            $this->log('New product added', 'debug', [$newProduct]);

            $newProductId = $newProduct['d']['ID'] ?? null;
            if (isset($newProductId)) {
                $product->setExactId($newProductId);
                $this->syncProductPricesToExact($product);

                return $newProductId;
            }
        } catch (BadResponseException $e) {
            if (! $isRepeatAttempt && $this->shouldRetryProductExactCallAfterAuthFailure($e)) {
                return $this->createProductInExactWithAuthRetry($product, true);
            }

            $this->throwProductExactApiException($e);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('Exact Online API error: '.$e->getMessage(), 'error');
            throw $e;
        }

        return null;
    }

    public function updateProductInExact(Product $product): bool
    {
        return $this->updateProductInExactWithAuthRetry($product, false);
    }

    private function updateProductInExactWithAuthRetry(Product $product, bool $isRepeatAttempt): bool
    {
        if (! $product->getExactId()) {
            $this->log('No Exact ID found for product '.$product->getId(), 'error');

            return false;
        }

        $url = $this->url("v1/{$this->division}/logistics/Items(guid'{$product->getExactId()}')");
        $body = $this->buildProductExactPayload($product);

        $this->log('Updating product in Exact: ', 'debug', $body);

        try {
            $response = $this->client->put($url, [
                'exact_service' => 'ExactOnlineService',
                'headers' => $this->exactJsonRequestHeaders(),
                'body' => json_encode($body),
            ]);

            if (in_array($response->getStatusCode(), [200, 204], true)) {
                $this->syncProductPricesToExact($product);
                $this->log("Product '".trim($product->getName())."' (ID: {$product->getId()}) updated successfully in Exact Online.", 'info');

                return true;
            }

            $this->log("Failed to update product '".trim($product->getName())."' (ID: {$product->getId()}) in Exact Online. Status code: ".$response->getStatusCode(), 'error');

            return false;
        } catch (BadResponseException $e) {
            if (! $isRepeatAttempt && $this->shouldRetryProductExactCallAfterAuthFailure($e)) {
                return $this->updateProductInExactWithAuthRetry($product, true);
            }

            $this->throwProductExactApiException($e);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('Error updating product in Exact Online: '.$e->getMessage(), 'error');
            throw $e;
        }
    }

    private function shouldRetryProductExactCallAfterAuthFailure(BadResponseException $exception): bool
    {
        if (! ExactApiErrorMessage::isAuthenticationFailure($exception->getResponse())) {
            return false;
        }

        return $this->forceRefreshAccessToken() !== false;
    }

    public function linkSupplierToProduct(Product $product, string $productId): bool
    {
        // Check if the product has an Exact ID
        if (empty($productId)) {
            $this->log('No Exact ID found for product ' . $product->getId(), 'error');
            return false;
        }

        $url = $this->url("v1/{$this->division}/logistics/SupplierItem");

        $supplier = $product->getSupplier();
        if (empty($supplier) || !$supplier->sync_with_exact) {
            return false;
        }

        $body = [
            'Item' => $productId,
            'Supplier' => $supplier->exact_id,
            'ItemUnit' => self::UNITS['pc']['ID'],
            'PurchaseUnit' => self::UNITS['pc']['Code'],
            'PurchasePrice' => (float) ($product->company_purchase_price ?? 0),
            'MainSupplier' => true,
        ];

        $this->log("Linking supplier to product in Exact: ", 'debug', $body);

        try {
            // Add new supplier item
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);
            $responseBody = json_decode($response->getBody()->getContents(), true);

            // Check if the update was successful
            if (in_array($response->getStatusCode(), [200, 201, 204])) {
                $newSupplierItemId = $responseBody['d']['ID'] ?? null;
                if ($newSupplierItemId) {
                    $product->setExactSupplierItemId($newSupplierItemId);
                    $product->save();
                }

                $this->log("Product '{$product->getName()}' (ID: {$product->getId()}) linked successfully to supplier '{$supplier->getName()}' in Exact Online.", 'info');
                return true;
            } else {
                $this->log("Failed to link product '{$product->getName()}' (ID: {$product->getId()}) to supplier '{$supplier->getName()}' in Exact Online. Status code: " . $response->getStatusCode(), 'error');
                return false;
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error linking product to supplier in Exact Online: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log("Error linking product to supplier in Exact Online: " . $e->getMessage(), 'error');
        }

        return false;
    }

    public function getSupplierItemsForProduct(string $productId): array
    {
        $url = $this->url("v1/{$this->division}/logistics/SupplierItem");
        $filter = sprintf("Item eq guid'%s'", $productId);

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['d']['results'] ?? [];
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error fetching supplier items for product in Exact Online: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log("Error fetching supplier items for product in Exact Online: " . $e->getMessage(), 'error');
        }

        return [];
    }

    /**
     * Find the Exact supplier item for a product by Item guid where MainSupplier is true.
     *
     * @param string $itemGuid The Exact Item GUID.
     * @return string|null The supplier item data if found, otherwise null.
     */
    public function getSupplierItem(string $id): ?string
    {
        $url = $this->url("v1/{$this->division}/logistics/SupplierItem");
        $filter = sprintf("ID eq guid'%s' and MainSupplier eq true", $id);

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['d']['results'])) {
                return $data['d']['results'][0]['ID']; // Return the first matching supplier item
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error fetching supplier item in Exact Online: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log("Error fetching supplier item in Exact Online: " . $e->getMessage(), 'error');
        }

        return null;
    }

    /**
     * Fetches customer data from Exact Online based on the customer's code.
     *
     * @param string $customerCode The code of the customer to fetch.
     * @return array|null The customer data if found, otherwise null.
     * @throws GuzzleException
     */
    public function getCustomerData(string $customerCode): ?array
    {
        $this->log('Fetching customer data for code: ' . $customerCode);

        $url = $this->url('v1/' . $this->division . '/crm/Accounts');

        // The spaces are necessary to match the exact length of the customer code in Exact Online
        $filter = sprintf("Code eq '              %s'", $customerCode);

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                    //'$select' => $select,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['d']['results'])) {
                return reset($data['d']['results']); // Return the first matching customer
            }
        } catch (GuzzleException $e) {
            $this->log('Failed to fetch customer data by code: ' . $e->getMessage(), 'error');
            throw $e;
        }

        return null;
    }

    /**
     * Fetch Items from Exact (paginated, no OData filter).
     */
    public function getProductsFromExact(int $top = 100, int $skip = 0): array
    {
        return resolve(ExactProducts::class)->fetchPage($top, $skip);
    }




    /**
     * Search for a product in Exact Online by its name.
     *
     * @param string $productCode
     * @return array|null Returns an array with product details if found, otherwise null.
     */
    public function findExactProductByCode(string $productCode): ?array
    {
        $url = $this->url('v1/' . $this->division . '/logistics/Items');
        $query = [
            '$filter' => "Code eq '" . urlencode($productCode) . "'",
        ];

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'query' => $query
            ]);

            $items = json_decode($response->getBody()->getContents(), true);

            if (!empty($items['d']['results'])) {
                $id = reset($items['d']['results'])['ID'];
                $itemGroupId = reset($items['d']['results'])['ItemGroup'];
                $productName = reset($items['d']['results'])['Description'];

                return [$id, $itemGroupId, $productName];
            }
        } catch (GuzzleException $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function createCustomer(Customer $customer): ?string
    {
        $exactAccounts = app(ExactAccounts::class);

        $exactId = $exactAccounts->createAccountForCustomer($customer);

        if (empty($exactId)) {
            return null;
        }

        try {
            $bankAccountResult = $this->createOrUpdateBankAccount($exactId, $customer, new: true);

            if (! $bankAccountResult) {
                $this->deleteResource('crm/Accounts', $exactId);

                return null;
            }
        } catch (Throwable $e) {
            try {
                $this->deleteResource('crm/Accounts', $exactId);
            } catch (Throwable $cleanupFailed) {
                $this->log('createCustomer: rollback verwijderen account mislukt: '.$cleanupFailed->getMessage(), 'error');
            }

            throw $e;
        }

        $customer->exact_id = $exactId;
        $exactAccounts->updateMainContactForCustomer($customer);

        return $exactId;
    }

    public function updateCustomer(Customer $customer): bool
    {
        $exactAccounts = app(ExactAccounts::class);

        $success = $exactAccounts->updateAccountForCustomer($customer);

        if (! $success) {
            return false;
        }

        $this->createOrUpdateBankAccount($customer->exact_id, $customer);
        $exactAccounts->updateMainContactForCustomer($customer);

        return true;
    }

    private function createOrUpdateBankAccount(string $accountId, Customer $customer, bool $new = false): bool
    {
        $filter = "Main eq true";
        $bankAccountBody = [
            'Account'     => $accountId,
            'BankAccount' => $customer->iban,
            'BICCode'     => $customer->bic,
            'Main'        => true,
        ];

        $this->log(($new ? "Creating" : "Creating/updating") . " bank account for company: ", 'debug', $bankAccountBody);

        if (!$new) {
            // Fetch existing bank accounts with the filter
            $bankAccountsUrl = $this->url("v1/{$this->division}/crm/Accounts(guid'{$accountId}')/BankAccounts");

            try {
                $response = $this->client->get($bankAccountsUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        '$filter' => $filter,
                    ],
                ]);

                $bankAccounts = json_decode($response->getBody()->getContents(), true)['d']['results'] ?? [];

                if (empty($customer->iban) && !empty($bankAccounts)) {
                    // Delete all existing bank accounts if IBAN is empty
                    foreach ($bankAccounts as $bankAccount) {
                        try {
                            $this->deleteResource('crm/BankAccounts', $bankAccount['ID']);
                            return true;
                        } catch (Throwable $e) {
                            $this->log('Error deleting bank account: ' . (string)$e, 'error');
                            return false;
                        }
                    }
                }

                if (!empty($bankAccounts)) {
                    // Update the first matching bank account
                    $bankAccountId = $bankAccounts[0]['ID'];
                    $bankAccountUrl = $this->url("v1/{$this->division}/crm/BankAccounts(guid'{$bankAccountId}')");

                    $this->log("Updating bank account for company: ", 'debug', $bankAccountBody);

                    try {
                        $this->client->put($bankAccountUrl, [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json',
                            ],
                            'body' => json_encode($bankAccountBody),
                        ]);

                        $this->log("Bank account updated successfully for company.", 'info');
                        return true;
                    } catch (BadResponseException $e) {
                        $response = $e->getResponse();
                        $body = $response !== null ? (string) $response->getBody()->getContents() : '';
                        $this->log(
                            'Error updating bank account: '.$e->getMessage().($body !== '' ? ' body='.$body : ''),
                            'error'
                        );
                        throw new RuntimeException(
                            ExactApiErrorMessage::fromResponseBody($body) ?? $e->getMessage(),
                            0,
                            $e
                        );
                    } catch (Throwable $e) {
                        $this->log('Error updating bank account: ' . (string) $e, 'error');

                        return false;
                    }
                }
            } catch (Throwable $e) {
                $this->log('Error fetching bank accounts: ' . (string)$e, 'error');
            }
        }

        if (empty($customer->iban)) {
            $this->log('No IBAN provided, skipping bank account creation.', 'info');
            return true;
        }

        // Create a new bank account if none exists or $new is true
        $bankAccountUrl = $this->url("v1/{$this->division}/crm/BankAccounts");

            $this->log("Creating bank account for company: ", 'debug', $bankAccountBody);

        try {
            $this->client->post($bankAccountUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($bankAccountBody),
            ]);

            $this->log("Bank account created successfully for company.", 'info');
            return true;
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $body = $response !== null ? (string) $response->getBody()->getContents() : '';
            $this->log(
                'Error creating bank account: '.$e->getMessage().($body !== '' ? ' body='.$body : ''),
                'error'
            );
            throw new RuntimeException(
                ExactApiErrorMessage::fromResponseBody($body) ?? $e->getMessage(),
                0,
                $e
            );
        } catch (Throwable $e) {
            $this->log('Error creating bank account: ' . (string) $e, 'error');
        }

        return false;
    }

    protected function formatPhoneNumber($number)
    {
        // Remove any spaces from the number
        $number = str_replace(' ', '', $number);

        // Check if the number starts with '0' and replace the first '0' with '+31'
        if (strpos($number, '0') === 0) {
            $number = '+31' . substr($number, 1);
        }

        return $number;
    }

    /**
     * Create products in Exact Online for local products that do not yet have an Exact ID.
     *
     * @return bool
     */
    public function syncNewProducts(): bool
    {
        $products = Product::query()
            ->whereNotNull('exact_article_group_id')
            ->whereNull('exact_id')
            ->orderBy('exact_synced_at', 'asc')
            ->get();

        foreach ($products as $product) {
            $productName = $product->getName();
            if (str_ends_with($productName, '-kopie')) {
                continue;
            }

            $this->log("Creating new Exact product for local product ID: {$product->id}");

            $exactProductId = $this->createProductInExact($product);
            sleep(5); // prevent 429 errors

            if ($exactProductId) {
                $product->setExactId($exactProductId);
                $product->setExactSyncedAt(now());
                $product->save();
                $this->log("Product '{$product->getName()}' (ID: {$product->getId()}) created in Exact Online using ID: {$exactProductId}", 'info');
            } else {
                $this->log("Could not create product '{$product->getName()}' (ID: {$product->getId()}) in Exact Online", 'error');
                $product->save();
            }
        }

        return true;
    }

    /**
     * Sync/update products that are expected to exist in Exact Online.
     *
     * @return bool
     */
    public function syncUpdatedProducts(): bool
    {
        $products = Product::query()
            ->whereNotNull('exact_article_group_id')
            ->whereNotNull('exact_id')
            ->orderBy('exact_synced_at', 'asc')
            ->get();

        foreach ($products as $product) {
            // Skip products that have not been updated since last sync
            if ($product->getUpdatedAt()->lessThanOrEqualTo($product->getExactSyncedAt())) {
                continue;
            }

            $productName = $product->getName();

            $this->log("Syncing product ID: {$product->id}");

            $exactCode = $product->getExactCode();
            $found = $this->findExactProductByCode($exactCode);

            if ($found) {
                [$exactProductId, $itemGroupId, $exactProductName] = $found;
                $supplierItems = $this->getSupplierItemsForProduct($exactProductId);

                // Check if supplier items match the database
                $supplier = $product->getSupplier();
                $supplierId = $supplier?->exact_id;
                $supplierItemId = $product->getExactSupplierItemId();

                $mismatchedItems = array_filter($supplierItems, function ($item) use ($supplierId, $supplierItemId) {
                    return $item['Supplier'] !== $supplierId || $item['ID'] !== $supplierItemId;
                });

                // Delete mismatched supplier items
                foreach ($mismatchedItems as $item) {
                    $this->deleteResource('logistics/SupplierItem', $item['ID']);
                }

                // Add a new supplier item only if no valid supplier item exists
                if (empty($supplierItems) || !empty($mismatchedItems)) {
                    $this->linkSupplierToProduct($product, $exactProductId);
                }

                // Update product in Exact if necessary
                if (trim($productName) !== trim($exactProductName) || $itemGroupId !== $product->exactArticleGroup->getGuid()) {
                    $this->updateProductInExact($product);
                    $product->setExactSyncedAt(now());
                    $product->save();
                    continue;
                }

                if ($product->getExactId() !== $exactProductId) {
                    $product->setExactId($exactProductId);
                }
                $product->setExactSyncedAt(now());
                $product->save();
                $this->log("Product '{$product->getName()}' (ID: {$product->getId()}) updated using Exact ID: {$exactProductId}", 'info');
            } else {
                $this->log("Product '{$product->getName()}' (ID: {$product->getId()}) with code '{$exactCode}' not found in Exact Online", 'error');
            }
        }

        return true;
    }

    /**
     * @deprecated Use ExactInvoice::getPaidSalesInvoices() instead.
     */
    public function getPaidSalesInvoices(array $ids): ?array
    {
        return (new \App\Services\Exact\Invoices\ExactInvoice($this))->getPaidSalesInvoices($ids);
    }

    public function createSupplier(Supplier $supplier): ?string
    {
        return app(ExactSuppliers::class)->createAccountForSupplier($supplier);
    }

    public function getSupplier(string $exactId): ?array
    {
        return app(ExactSuppliers::class)->getAccountByExactId($exactId);
    }

    public function updateSupplier(Supplier $supplier): bool
    {
        return app(ExactSuppliers::class)->updateAccountForSupplier($supplier);
    }

    public function getCustomer(string $exactId): ?array
    {
        return app(ExactAccounts::class)->getAccountByExactId($exactId);
    }

    /**
     * Create a document in Exact Online.
     *
     * @param array $data Document data (Subject, Type, Account, etc.)
     * @return string|null Document ID if created, null otherwise.
     */
    public function createDocument(array $data): ?string
    {
        $url = $this->url("v1/{$this->division}/documents/Documents");

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($data),
            ]);
            $result = json_decode($response->getBody()->getContents(), true);
            if (isset($result['d']['ID'])) {
                return $result['d']['ID'];
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error occured while creating document: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log('Exact Online API error: ' . (string)$e, 'error');
        }
        return null;
    }

    /**
     * Retrieves attachments for a given document from Exact Online.
     *
     * @param string $documentId The ID of the document.
     * @return array|null Returns an array of attachments if found, null otherwise.
     */
    public function getDocumentAttachments(string $documentId): ?array
    {
        $url = $this->url("v1/{$this->division}/documents/DocumentAttachments");
        $filter = sprintf("Document eq guid'%s'", $documentId);

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    '$filter' => $filter,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['d']['results'])) {
                return $data['d']['results'];
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error retrieving document attachments: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log('Exact Online API error: ' . (string)$e, 'error');
        }

        return null;
    }

    /**
     * Uploads a document attachment to an existing document in Exact Online.
     * @param string $documentId The ID of the document to which the attachment will be added.
     * @param string $filePath The path to the file to be uploaded as an attachment.
     * @return bool Returns true if the upload was successful, false otherwise.
     */
    public function uploadDocumentAttachment(string $documentId, string $filePath): string|bool
    {
        $url = $this->url("v1/{$this->division}/documents/DocumentAttachments");

        try {
            $fileContents = file_get_contents($filePath);
            if ($fileContents === false || strlen($fileContents) === 0) {
                $this->log("Could not read Exact document attachment file or file is empty: {$filePath}", 'error');
                return false;
            }
            $base64Attachment = base64_encode($fileContents);

            $body = [
                'Document' => $documentId,
                'FileName' => basename($filePath),
                'Attachment' => $base64Attachment,
            ];
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if ($response->getStatusCode() === 201 && isset($data['d']['ID'])) {
                return $data['d']['ID'];
            }
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
            $this->log("Error occured while uploading document attachment: {$response?->getStatusCode()} {$response?->getBody()?->getContents()}", 'error');
        } catch (Throwable $e) {
            $this->log('Exact Online API error: ' . (string)$e, 'error');
        }
        return false;
    }

    public function getCompany(string $exactId): ?array
    {
        return app(ExactAccounts::class)->getAccountByExactId($exactId);
    }

    public function syncGLAccounts(): bool
    {
        $maxTimestamp = ExactGLAccount::max('timestamp');
        $lastTimestamp = !empty($maxTimestamp) ? "{$maxTimestamp}L" : 1; // timestamp should be Long format with 'L' suffix

        $url = $this->url('v1/' . $this->division . '/sync/Financial/GLAccounts');
        $query = [
            '$filter' => "Timestamp gt $lastTimestamp",
            '$select' => 'BalanceSide,Code,Description,ID,Type,VATCode,Timestamp',
        ];

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (empty($data['d']['results'])) {
                return true;
            }

            foreach ($data['d']['results'] as $item) {
                ExactGLAccount::updateOrCreate(
                    ['guid' => $item['ID']],
                    [
                        'code' => trim($item['Code']),
                        'name' => $item['Description'],
                        'type' => $item['Type'],
                        'balance_side' => $item['BalanceSide'],
                        'vat_code' => $item['VATCode'],
                        'timestamp' => $item['Timestamp'],
                    ],
                );
                $this->log("GL Account synced: {$item['Code']} - {$item['Description']} - {$item['ID']} - {$item['Timestamp']} - {$item['Type']}", 'info');
            }

            return true;
        } catch (Throwable $e) {
            $this->log('Failed to sync GL accounts: ' . (string)$e, 'error');
            return false;
        }
    }

    public function syncVATCodes(): bool
    {
        $url = $this->url('v1/' . $this->division . '/vat/VATCodes');
        $query = [
            '$select' => 'ID,Code,Description,GLToClaim,GLToPay,IsBlocked,Modified,Percentage,Type,VATTransactionType',
        ];

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (empty($data['d']['results'])) {
                return true;
            }

            $exactGuids = array_map(fn($item) => $item['ID'], $data['d']['results']);

            foreach ($data['d']['results'] as $item) {
                $existingRecord = ExactVATCode::where('guid', $item['ID'])->first();

                if (!$existingRecord) {
                    // Create new record
                    ExactVATCode::create([
                        'guid' => $item['ID'],
                        'code' => trim($item['Code']),
                        'name' => $item['Description'],
                        'gl_to_claim' => $item['GLToClaim'],
                        'gl_to_pay' => $item['GLToPay'],
                        'is_blocked' => $item['IsBlocked'],
                        'modified' => $this->parseDotNetDate($item['Modified'], withoutMillis: true),
                        'percentage' => $item['Percentage'],
                        'type' => $item['Type'],
                        'vat_transaction_type' => $item['VATTransactionType'],
                    ]);
                    $this->log("VAT Code added to database: " . json_encode($item), 'info');
                } elseif ($this->parseDotNetDate($item['Modified'], withoutMillis: true)->greaterThan($existingRecord->modified)) {
                    // Update existing record
                    $existingRecord->update([
                        'code' => trim($item['Code']),
                        'name' => $item['Description'],
                        'gl_to_claim' => $item['GLToClaim'],
                        'gl_to_pay' => $item['GLToPay'],
                        'is_blocked' => $item['IsBlocked'],
                        'modified' => $this->parseDotNetDate($item['Modified'], withoutMillis: true),
                        'percentage' => $item['Percentage'],
                        'type' => $item['Type'],
                        'vat_transaction_type' => $item['VATTransactionType'],
                    ]);
                    $this->log("VAT Code updated in database: " . json_encode($item), 'info');
                }
            }

            // Delete records that no longer exist in Exact
            ExactVATCode::whereNotIn('guid', $exactGuids)->delete();

            return true;
        } catch (Throwable $e) {
            $this->log('Failed to sync VAT codes: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function syncPaymentConditions(): bool
    {
        $url = $this->url('v1/' . $this->division . '/cashflow/PaymentConditions');
        $query = [
            '$select' => 'ID,Code,Description,Modified,PaymentDays,PaymentEndOfMonths,PaymentMethod',
        ];

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getCurrentAccessToken(),
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (empty($data['d']['results'])) {
                return true;
            }

            $exactGuids = array_map(fn($item) => $item['ID'], $data['d']['results']);

            foreach ($data['d']['results'] as $item) {
                $existingRecord = ExactPaymentCondition::where('guid', $item['ID'])->first();

                if (!$existingRecord) {
                    // Create new record

                    ExactPaymentCondition::create([
                        'guid' => $item['ID'],
                        'code' => trim($item['Code']),
                        'name' => $item['Description'],
                        'payment_days' => $item['PaymentDays'],
                        'payment_end_of_months' => $item['PaymentEndOfMonths'],
                        'payment_method' => $item['PaymentMethod'],
                        'modified' => $this->parseDotNetDate($item['Modified']),
                    ]);
                    $this->log("Payment condition added to database: " . json_encode($item), 'info');
                } elseif ($this->parseDotNetDate($item['Modified'], withoutMillis: true)->greaterThan($existingRecord->modified)) {
                    // Update existing record
                    $existingRecord->update([
                        'code' => trim($item['Code']),
                        'name' => $item['Description'],
                        'payment_days' => $item['PaymentDays'],
                        'payment_end_of_months' => $item['PaymentEndOfMonths'],
                        'payment_method' => $item['PaymentMethod'],
                        'modified' => $this->parseDotNetDate($item['Modified'], withoutMillis: true),
                    ]);
                    $this->log("Payment condition updated in database: " . json_encode($item), 'info');
                }
            }

            // Delete records that no longer exist in Exact
            ExactPaymentCondition::whereNotIn('guid', $exactGuids)->delete();

            return true;
        } catch (Throwable $e) {
            $this->log('Failed to sync payment conditions: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Parse from a .NET JSON date string (e.g. "/Date(1688169600000)/").
     *
     * @param string $date
     * @return Carbon
     */
    public function parseDotNetDate(string $date, bool $withoutMillis = false): Carbon
    {
        // Extract the timestamp in milliseconds from the .NET JSON date string
        $timestampMs = (int)substr($date, 6, -2);
        $date = Carbon::createFromTimestampMs($timestampMs, 'Europe/Amsterdam');

        // Remove milliseconds if $withoutMillis is true
        if ($withoutMillis) {
            $date = $date->setMilliseconds(0);
        }

        return $date;
    }
}

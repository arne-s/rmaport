<?php

namespace App\Support\FormImport;

use App\Models\FormImportConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FormImportApiClient
{
    public function __construct(
        private readonly FormImportFormSchemaNormalizer $schemaNormalizer = new FormImportFormSchemaNormalizer,
    ) {}

    public function testConnection(FormImportConnection $connection): void
    {
        $this->assertRestApiAvailable($connection);

        $response = $this->request($connection)->get($this->endpoint($connection, '/forms'));

        $this->ensureSuccessful($response, 'Kan formulieren niet ophalen.');
    }

    public function assertRestApiAvailable(FormImportConnection $connection): void
    {
        $response = Http::baseUrl($connection->normalizedBaseUrl().'/wp-json/')
            ->acceptJson()
            ->timeout(30)
            ->get('');

        if (! $response->successful()) {
            throw new RuntimeException(
                'Kan WordPress REST API niet bereiken op '.$connection->normalizedBaseUrl()
                .'. Controleer of de URL klopt en of autovision.test bereikbaar is vanaf deze server.'
            );
        }

        $namespaces = $response->json('namespaces') ?? [];

        if (! in_array('gf/v2', $namespaces, true)) {
            throw new RuntimeException(
                'Gravity Forms REST API is niet ingeschakeld op deze WordPress-site. '
                .'Ga naar Forms → Settings → REST API en vink "Enable access to the API" (Ingeschakeld) aan. '
                .'Alleen een API-key aanmaken is niet genoeg.'
            );
        }
    }

    /**
     * @return list<array{id: int, title: string, is_active: bool}>
     */
    public function listForms(FormImportConnection $connection): array
    {
        $response = $this->request($connection)->get($this->endpoint($connection, '/forms'));

        $this->ensureSuccessful($response, 'Kan formulieren niet ophalen.');

        $payload = $response->json();

        if (! is_array($payload)) {
            return [];
        }

        $forms = [];

        foreach ($payload as $form) {
            if (! is_array($form)) {
                continue;
            }

            $id = (int) ($form['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $forms[] = [
                'id' => $id,
                'title' => (string) ($form['title'] ?? "Formulier {$id}"),
                'is_active' => (string) ($form['is_active'] ?? '1') === '1',
            ];
        }

        return $forms;
    }

    /**
     * @return list<array{id: string, label: string, type: string}>
     */
    public function listFormFields(FormImportConnection $connection, int $formId): array
    {
        $form = $this->getForm($connection, $formId);

        return $this->schemaNormalizer->normalizeFields($form);
    }

    /**
     * @return array<string, mixed>
     */
    public function getForm(FormImportConnection $connection, int $formId): array
    {
        $response = $this->request($connection)->get($this->endpoint($connection, "/forms/{$formId}"));

        $this->ensureSuccessful($response, 'Kan formuliergegevens niet ophalen.');

        $form = $response->json();

        if (! is_array($form)) {
            throw new RuntimeException('Ongeldig antwoord bij ophalen formulier.');
        }

        return $form;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, total_count: int}
     */
    public function getEntries(
        FormImportConnection $connection,
        int $formId,
        ?int $sinceEntryId = null,
        int $page = 1,
        ?int $pageSize = null,
    ): array {
        $pageSize ??= config('form-import.page_size', 100);

        $search = ['status' => 'active'];

        if ($sinceEntryId !== null && $sinceEntryId > 0) {
            $search['field_filters'] = [
                [
                    'key' => 'id',
                    'operator' => '>',
                    'value' => (string) $sinceEntryId,
                ],
            ];
        }

        $query = [
            'paging' => [
                'page_size' => $pageSize,
                'current_page' => $page,
            ],
            'sorting' => [
                'key' => 'id',
                'direction' => 'ASC',
                'is_numeric' => true,
            ],
            'search' => json_encode($search, JSON_THROW_ON_ERROR),
        ];

        $response = $this->request($connection)->get(
            $this->endpoint($connection, "/forms/{$formId}/entries"),
            $query,
        );

        $this->ensureSuccessful($response, 'Kan inzendingen niet ophalen.');

        $payload = $response->json();

        if (! is_array($payload)) {
            return ['entries' => [], 'total_count' => 0];
        }

        if (isset($payload['entries']) && is_array($payload['entries'])) {
            return [
                'entries' => array_values(array_filter($payload['entries'], is_array(...))),
                'total_count' => (int) ($payload['total_count'] ?? count($payload['entries'])),
            ];
        }

        if (array_is_list($payload)) {
            return [
                'entries' => array_values(array_filter($payload, is_array(...))),
                'total_count' => count($payload),
            ];
        }

        return ['entries' => [], 'total_count' => 0];
    }

    private function request(FormImportConnection $connection): PendingRequest
    {
        return Http::baseUrl($connection->apiBaseUrl())
            ->withBasicAuth($connection->username, $connection->api_token)
            ->acceptJson()
            ->timeout(30);
    }

    private function endpoint(FormImportConnection $connection, string $path): string
    {
        return ltrim($path, '/');
    }

    private function ensureSuccessful(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = trim((string) ($response->json('message') ?? $response->body()));

        if ($response->status() === 404) {
            throw new RuntimeException(
                "{$message} (404): Geen GF REST API route gevonden. "
                .'Schakel in WordPress Forms → Settings → REST API "Enable access to the API" in.'
                .($detail !== '' ? " WordPress: {$detail}" : '')
            );
        }

        if ($response->status() === 401) {
            throw new RuntimeException(
                "{$message} (401): Authenticatie mislukt. Gebruik klantsleutel als gebruikersnaam en klantgeheim als API-token."
                .($detail !== '' ? " WordPress: {$detail}" : '')
            );
        }

        if ($detail !== '') {
            throw new RuntimeException("{$message} ({$response->status()}): {$detail}");
        }

        throw new RuntimeException("{$message} (HTTP {$response->status()}).");
    }
}

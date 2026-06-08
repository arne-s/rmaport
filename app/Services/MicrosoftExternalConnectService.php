<?php

namespace App\Services;

use App\Models\MicrosoftMailToken;
use App\Models\MicrosoftToken;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class MicrosoftExternalConnectService
{
    private const AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    private const SCOPES = [
        'Calendars.ReadWrite',
        'MailboxSettings.Read',
        'Mail.Send',
        'offline_access',
        'User.Read',
    ];

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private Client $client;

    public function __construct()
    {
        $this->clientId = (string) config('services.microsoft.client_id', '');
        $this->clientSecret = (string) config('services.microsoft.client_secret', '');
        $this->redirectUri = (string) config('services.microsoft.redirect', '');
        $this->client = new Client(['timeout' => 30]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $query = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'response_mode' => 'query',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($query);
    }

    public function saveAccessTokens(string $code): ?string
    {
        $result = $this->requestToken([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $result['ok']) {
            return $this->formatTokenError('token exchange', $result);
        }

        $response = $result['data'];

        if (empty($response['access_token'])) {
            Log::error('Microsoft External Connect: no access_token in response', $response);

            return 'Microsoft gaf geen access token terug.';
        }

        $email = $this->resolveEmail($response['access_token']);

        if ($email === null) {
            Log::error('Microsoft External Connect: could not resolve email after token exchange');

            return 'Microsoft-account kon niet worden bepaald.';
        }

        $tokenPayload = [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ];

        MicrosoftToken::updateOrCreate(
            ['microsoft_email' => $email],
            [
                ...$tokenPayload,
                'timezone' => $this->resolveTimezone($response['access_token']),
            ],
        );

        MicrosoftMailToken::updateOrCreate(
            ['microsoft_email' => $email],
            $tokenPayload,
        );

        return null;
    }

    /**
     * @param  array<string, string>  $formParams
     * @return array{ok: bool, status: int, data: array<string, mixed>}
     */
    private function requestToken(array $formParams): array
    {
        try {
            $httpResponse = $this->client->post(self::TOKEN_URL, [
                'form_params' => $formParams,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            Log::error('Microsoft External Connect: token request transport error', [
                'error' => $e->getMessage(),
                'grant_type' => $formParams['grant_type'] ?? null,
            ]);

            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'transport_error', 'error_description' => $e->getMessage()]];
        }

        $status = $httpResponse->getStatusCode();
        $data = json_decode($httpResponse->getBody()->getContents(), true) ?? [];

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * @param  array{ok: bool, status: int, data: array<string, mixed>}  $result
     */
    private function formatTokenError(string $context, array $result): string
    {
        Log::error('Microsoft External Connect: ' . $context . ' failed', [
            'status' => $result['status'],
            'error' => $result['data']['error'] ?? null,
            'error_description' => $result['data']['error_description'] ?? null,
            'response' => $result['data'],
            'redirect_uri' => $this->redirectUri,
        ]);

        $description = $result['data']['error_description'] ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $error = $result['data']['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return 'Microsoft OAuth fout: ' . $error;
        }

        return 'Koppelen mislukt. Controleer MICROSOFT_REDIRECT_URI (moet exact overeenkomen met de callback-URL in Azure).';
    }

    private function resolveTimezone(string $accessToken): string
    {
        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me/mailboxSettings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => ['$select' => 'timeZone'],
            ])->getBody()->getContents(), true);

            return $response['timeZone'] ?? 'Europe/Amsterdam';
        } catch (\Exception) {
            return 'Europe/Amsterdam';
        }
    }

    private function resolveEmail(string $accessToken): ?string
    {
        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'query' => ['$select' => 'mail,userPrincipalName'],
            ])->getBody()->getContents(), true);

            return $response['mail'] ?? $response['userPrincipalName'] ?? null;
        } catch (GuzzleException) {
            return null;
        }
    }
}

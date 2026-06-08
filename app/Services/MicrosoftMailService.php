<?php

namespace App\Services;

use App\Models\MicrosoftMailToken;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class MicrosoftMailService
{
    private const AUTH_URL  = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    private const SCOPES = [
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
        $this->clientId     = (string) config('services.microsoft.client_id', '');
        $this->clientSecret = (string) config('services.microsoft.client_secret', '');
        $this->redirectUri  = (string) config('services.microsoft.mail_redirect', '');
        $this->client       = new Client(['timeout' => 30]);
    }

    public function getAuthorizationUrl(?string $redirectUri = null, ?string $state = null): string
    {
        $resolvedRedirectUri = $redirectUri ?? $this->redirectUri;

        $query = [
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $resolvedRedirectUri,
            'scope'         => implode(' ', self::SCOPES),
            'response_mode' => 'query',
            'prompt'        => 'select_account',
        ];

        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return self::AUTH_URL . '?' . http_build_query($query);
    }

    public function saveAccessToken(string $code, ?string $redirectUri = null): ?string
    {
        $resolvedRedirectUri = $redirectUri ?? $this->redirectUri;

        $result = $this->requestToken([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $resolvedRedirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $result['ok']) {
            return $this->formatTokenError('token exchange', $result);
        }

        $response = $result['data'];

        if (empty($response['access_token'])) {
            Log::error('Microsoft Mail OAuth: no access_token in response', $response);

            return 'Microsoft gaf geen access token terug.';
        }

        $email = $this->resolveEmail($response['access_token']);

        if ($email === null) {
            Log::error('Microsoft Mail OAuth: could not resolve email after token exchange');

            return 'Microsoft-account kon niet worden bepaald.';
        }

        MicrosoftMailToken::updateOrCreate(
            ['microsoft_email' => $email],
            [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? null,
                'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
            ],
        );

        return null;
    }

    public function disconnect(int $tokenId): void
    {
        MicrosoftMailToken::where('id', $tokenId)->delete();
    }

    public function proactivelyRefresh(MicrosoftMailToken $token): bool
    {
        if (empty($token->refresh_token)) {
            Log::warning('Microsoft Mail: proactive refresh skipped, no refresh_token', [
                'token_id' => $token->id,
                'microsoft_email' => $token->microsoft_email,
            ]);

            return false;
        }

        return $this->refreshToken($token) !== null;
    }

    public function getValidToken(int $tokenId): ?MicrosoftMailToken
    {
        $token = MicrosoftMailToken::find($tokenId);

        if (! $token) {
            return null;
        }

        if ($token->isExpired()) {
            $token = $this->refreshToken($token);
        }

        return $token;
    }

    /**
     * Send an email via the Microsoft Graph API using the stored token.
     *
     * @param  array<int, string>  $to    List of email addresses
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, array{path: string, name: string, mime: string}>  $attachments
     */
    public function sendMail(
        int $tokenId,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $htmlBody,
        array $attachments = []
    ): bool {
        $token = $this->getValidToken($tokenId);
        if (! $token) {
            Log::error('Microsoft Mail: no valid token for id ' . $tokenId);
            return false;
        }

        $message = [
            'subject' => $subject,
            'body'    => [
                'contentType' => 'HTML',
                'content'     => $htmlBody,
            ],
            'toRecipients'  => $this->buildRecipients($to),
            'ccRecipients'  => $this->buildRecipients($cc),
            'bccRecipients' => $this->buildRecipients($bcc),
        ];

        if ($attachments !== []) {
            $message['attachments'] = $this->buildAttachments($attachments);
        }

        try {
            $this->client->post(self::GRAPH_URL . '/me/sendMail', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode(['message' => $message, 'saveToSentItems' => true]),
            ]);

            return true;
        } catch (GuzzleException $e) {
            Log::error('Microsoft Mail: sendMail failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function refreshToken(MicrosoftMailToken $token): ?MicrosoftMailToken
    {
        if (empty($token->refresh_token)) {
            return null;
        }

        $result = $this->requestToken([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $token->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $result['ok']) {
            $this->logTokenError('refresh', $result, [
                'token_id' => $token->id,
                'microsoft_email' => $token->microsoft_email,
            ]);

            return null;
        }

        $response = $result['data'];

        if (empty($response['access_token'])) {
            Log::error('Microsoft Mail OAuth: token refresh failed', [
                'token_id' => $token->id,
                'response' => $response,
            ]);

            return null;
        }

        $token->update([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $token->refresh_token,
            'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ]);

        return $token->fresh();
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
            Log::error('Microsoft Mail OAuth: token request transport error', [
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
    private function logTokenError(string $context, array $result, array $extra = []): void
    {
        Log::error('Microsoft Mail OAuth: ' . $context . ' failed', [
            ...$extra,
            'status' => $result['status'],
            'error' => $result['data']['error'] ?? null,
            'error_description' => $result['data']['error_description'] ?? null,
            'response' => $result['data'],
            'redirect_uri' => $this->redirectUri,
            'client_id_prefix' => substr($this->clientId, 0, 8),
        ]);
    }

    /**
     * @param  array{ok: bool, status: int, data: array<string, mixed>}  $result
     */
    private function formatTokenError(string $context, array $result): string
    {
        $this->logTokenError($context, $result);

        $description = $result['data']['error_description'] ?? null;

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $error = $result['data']['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return 'Microsoft OAuth fout: ' . $error;
        }

        return 'Koppelen mislukt. Controleer MICROSOFT_MAIL_REDIRECT_URI (moet exact https://rd.afsd.nl/microsoft-mail/callback zijn).';
    }

    private function resolveEmail(string $accessToken): ?string
    {
        try {
            $response = json_decode($this->client->get(self::GRAPH_URL . '/me', [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'query'   => ['$select' => 'mail,userPrincipalName'],
            ])->getBody()->getContents(), true);

            return $response['mail'] ?? $response['userPrincipalName'] ?? null;
        } catch (GuzzleException) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<int, array{emailAddress: array{address: string}}>
     */
    private function buildRecipients(array $emails): array
    {
        return array_values(array_map(
            fn (string $email) => ['emailAddress' => ['address' => $email]],
            array_filter($emails, fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL))
        ));
    }

    /**
     * @param  array<int, array{path: string, name: string, mime: string}>  $attachments
     * @return array<int, array<string, mixed>>
     */
    private function buildAttachments(array $attachments): array
    {
        $result = [];

        foreach ($attachments as $attachment) {
            $path = $attachment['path'] ?? null;
            if (! is_string($path) || ! file_exists($path)) {
                continue;
            }

            $result[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $attachment['name'] ?? basename($path),
                'contentType'  => $attachment['mime'] ?? 'application/octet-stream',
                'contentBytes' => base64_encode((string) file_get_contents($path)),
            ];
        }

        return $result;
    }
}

<?php

namespace App\Support\Exact;

use Psr\Http\Message\ResponseInterface;

/**
 * Parses Exact Online JSON error payloads (REST and OData shapes) for user-visible messages.
 */
final class ExactApiErrorMessage
{
    public const EXACT_RECONNECT_MESSAGE = 'Exact Online is niet gekoppeld of de sessie is verlopen. Koppel Exact opnieuw via /exact-connect.';

    public static function isAuthenticationFailure(?ResponseInterface $response): bool
    {
        if ($response === null) {
            return false;
        }

        if ($response->getStatusCode() === 401) {
            return true;
        }

        $reason = $response->getHeaderLine('Reason');
        if ($reason !== '' && str_contains($reason, 'AuthenticationRequired')) {
            return true;
        }

        $wwwAuthenticate = $response->getHeaderLine('WWW-Authenticate');

        return $wwwAuthenticate !== '' && str_contains($wwwAuthenticate, 'Missing%20access%20token');
    }

    public static function fromResponse(?ResponseInterface $response): ?string
    {
        if ($response === null) {
            return null;
        }

        if (self::isAuthenticationFailure($response)) {
            return self::EXACT_RECONNECT_MESSAGE;
        }

        $reason = trim($response->getHeaderLine('Reason'));
        if ($reason !== '') {
            return self::finalize($reason);
        }

        $body = (string) $response->getBody()->getContents();

        return self::fromResponseBody($body !== '' ? $body : null);
    }
    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromDecodedJson(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        if (isset($data['error']) && is_array($data['error'])) {
            return self::finalize(self::normalizeMessagePayload($data['error']['message'] ?? null));
        }

        if (isset($data['odata.error']) && is_array($data['odata.error'])) {
            return self::finalize(self::normalizeMessagePayload($data['odata.error']['message'] ?? null));
        }

        return null;
    }

    public static function fromResponseBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return self::fromDecodedJson($decoded);
    }

    private static function normalizeMessagePayload(mixed $message): ?string
    {
        if (is_string($message)) {
            return $message !== '' ? $message : null;
        }

        if (is_array($message)) {
            $value = $message['value'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $nested = $message['message'] ?? null;

            return is_string($nested) && $nested !== '' ? $nested : null;
        }

        return null;
    }

    private static function finalize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $collapsed = preg_replace('/\r\n|\r/', "\n", $message);
        $trimmed = trim((string) $collapsed);

        return $trimmed !== '' ? $trimmed : null;
    }
}

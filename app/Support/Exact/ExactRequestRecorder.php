<?php

namespace App\Support\Exact;

use App\Models\ExactRequest;
use Psr\Http\Message\StreamInterface;
use Throwable;

class ExactRequestRecorder
{
    private const MAX_BODY_LENGTH = 131072;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function store(array $payload): void
    {
        try {
            ExactRequest::create($payload);
        } catch (Throwable) {
            // Never break Exact API flows because request logging fails.
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    public static function sanitizeHeaders(array $headers): array
    {
        $masked = [];
        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $headerValue = is_array($value) ? implode(', ', $value) : (string) $value;

            if (in_array($normalizedKey, ['authorization', 'cookie', 'set-cookie', 'x-api-key'], true)) {
                $masked[(string) $key] = '[REDACTED]';
                continue;
            }

            $masked[(string) $key] = $headerValue;
        }

        return $masked;
    }

    public static function sanitizeBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            self::maskSensitiveKeys($decoded);
            $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return self::truncate($encoded === false ? $body : $encoded);
        }

        return self::truncate($body);
    }

    public static function readStreamBody(?StreamInterface $stream): ?string
    {
        if ($stream === null) {
            return null;
        }

        try {
            if ($stream->isSeekable()) {
                $position = $stream->tell();
                $stream->rewind();
                $body = $stream->getContents();
                $stream->seek($position);

                return $body === '' ? null : $body;
            }

            $body = $stream->getContents();

            return $body === '' ? null : $body;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     */
    private static function maskSensitiveKeys(array &$data): void
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                self::maskSensitiveKeys($value);
                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower($key);
            if (in_array($normalizedKey, [
                'authorization',
                'access_token',
                'refresh_token',
                'client_secret',
                'password',
                'token',
                'api_key',
            ], true)) {
                $value = '[REDACTED]';
            }
        }
    }

    private static function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_BODY_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_BODY_LENGTH) . '...[TRUNCATED]';
    }
}


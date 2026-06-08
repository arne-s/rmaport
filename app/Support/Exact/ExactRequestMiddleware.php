<?php

namespace App\Support\Exact;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ExactRequestMiddleware
{
    public static function create(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $startedAt = now();
                $started = microtime(true);
                $correlationId = (string) Str::uuid();
                $service = self::resolveService($options);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $startedAt, $started, $correlationId, $service) {
                        $statusCode = $response->getStatusCode();

                        ExactRequestRecorder::store([
                            'direction' => 'outbound',
                            'service' => $service,
                            'method' => $request->getMethod(),
                            'endpoint' => $request->getUri()->getPath(),
                            'url' => (string) $request->getUri(),
                            'request_headers' => ExactRequestRecorder::sanitizeHeaders($request->getHeaders()),
                            'request_body' => ExactRequestRecorder::sanitizeBody(
                                ExactRequestRecorder::readStreamBody($request->getBody())
                            ),
                            'response_status' => $statusCode,
                            'response_headers' => ExactRequestRecorder::sanitizeHeaders($response->getHeaders()),
                            'response_body' => ExactRequestRecorder::sanitizeBody(
                                ExactRequestRecorder::readStreamBody($response->getBody())
                            ),
                            'duration_ms' => self::durationMs($started),
                            'succeeded' => $statusCode < 400,
                            'error_class' => null,
                            'error_message' => $statusCode >= 400 ? "HTTP {$statusCode}" : null,
                            'correlation_id' => $correlationId,
                            'requested_at' => $startedAt,
                            'responded_at' => now(),
                        ]);

                        return $response;
                    },
                    function ($reason) use ($request, $startedAt, $started, $correlationId, $service) {
                        $response = $reason instanceof RequestException ? $reason->getResponse() : null;
                        $errorClass = $reason instanceof Throwable ? $reason::class : gettype($reason);
                        $errorMessage = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;

                        ExactRequestRecorder::store([
                            'direction' => 'outbound',
                            'service' => $service,
                            'method' => $request->getMethod(),
                            'endpoint' => $request->getUri()->getPath(),
                            'url' => (string) $request->getUri(),
                            'request_headers' => ExactRequestRecorder::sanitizeHeaders($request->getHeaders()),
                            'request_body' => ExactRequestRecorder::sanitizeBody(
                                ExactRequestRecorder::readStreamBody($request->getBody())
                            ),
                            'response_status' => $response?->getStatusCode(),
                            'response_headers' => $response instanceof ResponseInterface
                                ? ExactRequestRecorder::sanitizeHeaders($response->getHeaders())
                                : null,
                            'response_body' => $response instanceof ResponseInterface
                                ? ExactRequestRecorder::sanitizeBody(ExactRequestRecorder::readStreamBody($response->getBody()))
                                : null,
                            'duration_ms' => self::durationMs($started),
                            'succeeded' => false,
                            'error_class' => $errorClass,
                            'error_message' => $errorMessage,
                            'correlation_id' => $correlationId,
                            'requested_at' => $startedAt,
                            'responded_at' => now(),
                        ]);

                        throw $reason;
                    }
                );
            };
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function resolveService(array $options): string
    {
        $service = $options['exact_service'] ?? null;
        if (is_string($service) && $service !== '') {
            return $service;
        }

        return 'ExactOnlineService';
    }

    private static function durationMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}


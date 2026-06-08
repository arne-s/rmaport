<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TravelTimeService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const ORS_MATRIX_URL = 'https://api.openrouteservice.org/v2/matrix/driving-car';

    /** Cache geocoding results for 7 days, routes for 1 day. */
    private const GEOCODE_TTL = 60 * 60 * 24 * 7;
    private const ROUTE_TTL   = 60 * 60 * 24;

    public function __construct(private readonly string $apiKey) {}

    /**
     * Calculate driving distance and duration between two addresses.
     *
     * @return array{from: string, to: string, distance_km: float, duration_minutes: int}|null
     */
    public function calculate(string $fromAddress, string $toAddress): ?array
    {
        if ($fromAddress === '' || $toAddress === '') {
            return null;
        }

        try {
            $fromCoords = $this->geocode($fromAddress);
            $toCoords   = $this->geocode($toAddress);

            $cacheKey = 'travel_time_route:' . md5($fromAddress . '|||' . $toAddress);

            return Cache::remember($cacheKey, self::ROUTE_TTL, function () use ($fromCoords, $toCoords, $fromAddress, $toAddress): array {
                return $this->fetchRoute($fromCoords, $toCoords, $fromAddress, $toAddress);
            });
        } catch (\Throwable $e) {
            Log::warning('TravelTimeService: ' . $e->getMessage(), [
                'from' => $fromAddress,
                'to'   => $toAddress,
            ]);

            return null;
        }
    }

    /**
     * Format a TravelTimeService result as a human-readable string.
     *
     * @param  array{from: string, to: string, distance_km: float, duration_minutes: int}  $result
     */
    public static function formatDuration(array $result): string
    {
        $minutes = $result['duration_minutes'];
        $hours   = intdiv($minutes, 60);
        $mins    = $minutes % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' ' . ($hours === 1 ? 'uur' : 'uur');
        }
        if ($mins > 0 || $hours === 0) {
            $parts[] = $mins . ' ' . ($mins === 1 ? 'minuut' : 'minuten');
        }

        return implode(', ', $parts);
    }

    /**
     * Geocode an address to [longitude, latitude] via Nominatim.
     *
     * @return array{float, float}
     */
    private function geocode(string $address): array
    {
        $cacheKey = 'travel_time_geocode:' . md5($address);

        return Cache::remember($cacheKey, self::GEOCODE_TTL, function () use ($address): array {
            $response = Http::withHeaders(['User-Agent' => 'RD Mobility Laravel App'])
                ->get(self::NOMINATIM_URL, [
                    'q'      => $address,
                    'format' => 'json',
                    'limit'  => 1,
                ]);

            $results = $response->json();

            if ($response->failed() || empty($results[0])) {
                throw new \RuntimeException("Adres niet gevonden via geocoding: {$address}");
            }

            return [(float) $results[0]['lon'], (float) $results[0]['lat']];
        });
    }

    /**
     * @param  array{float, float}  $fromCoords  [lon, lat]
     * @param  array{float, float}  $toCoords    [lon, lat]
     * @return array{from: string, to: string, distance_km: float, duration_minutes: int}
     */
    private function fetchRoute(array $fromCoords, array $toCoords, string $fromAddress, string $toAddress): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type'  => 'application/json',
        ])->post(self::ORS_MATRIX_URL, [
            'locations' => [$fromCoords, $toCoords],
            'metrics'   => ['duration', 'distance'],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('ORS API error: ' . $response->body());
        }

        $data = $response->json();

        return [
            'from'             => $fromAddress,
            'to'               => $toAddress,
            'distance_km'      => round($data['distances'][0][1] / 1000, 1),
            'duration_minutes' => (int) round($data['durations'][0][1] / 60),
        ];
    }
}

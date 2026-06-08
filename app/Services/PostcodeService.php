<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PostcodeService
{
    protected string $baseUrl = 'https://api.postcode.eu/nl/v1/addresses/postcode/';
    protected mixed $apiKey;
    protected mixed $apiSecret;

    public function __construct()
    {
        $this->apiKey = config('services.postcode.key');
        $this->apiSecret = config('services.postcode.secret');
    }

    public function fetchAddress(string $postcode, int $houseNumber, string $houseNumberAddition = ''): array
    {
        $postcode = strtolower($postcode);
        $houseNumberAddition = strtolower($houseNumberAddition);

        $cacheKey = "postcode:$postcode-$houseNumber-$houseNumberAddition" . date('Y-m-d-h');

        return Cache::remember($cacheKey, 60 * 24, function () use ($postcode, $houseNumber, $houseNumberAddition) {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->get($this->baseUrl . $postcode . '/' . $houseNumber . '/' . $houseNumberAddition);

            if (!in_array($response->status(), [200, 404])) {
                report('Postcode unknown response: ' . $response->status().': '. $response->reason());
            }

            return $response->json();
        });
    }
}

<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * Thin wrapper around the PostNL REST API.
 *
 * Endpoints used:
 *   GET  /shipment/v1_1/barcode   — generate a unique barcode
 *   POST /shipment/v2_2/label     — generate a shipping label PDF (and confirm the shipment)
 */
class PostNLService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim((string) config('postnl.api_url'), '/'),
            'headers'  => [
                'apikey'       => config('postnl.api_key'),
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);
    }

    /**
     * Generate a unique PostNL barcode string.
     *
     * Chooses EU or non-EU credentials from config based on the supplied ISO-2 country code.
     * Brievenbuspakje uses barcode type '2S' instead of '3S'.
     *
     * @throws RuntimeException
     */
    public function generateBarcode(string $countryCode = 'NL', string $parcelType = 'parcel'): string
    {
        $isEu    = $this->isEuCountry($countryCode);
        $segment = $isEu ? config('postnl.eu') : config('postnl.non_eu');

        $barcodeType = ($parcelType === 'mailbox') ? '2S' : $segment['barcode_type'];

        try {
            $response = $this->client->get('/shipment/v1_1/barcode', [
                'query' => [
                    'CustomerCode'   => $segment['customer_code'],
                    'CustomerNumber' => config('postnl.customer_number'),
                    'Type'           => $barcodeType,
                    'Serie'          => $segment['barcode_range'],
                ],
            ]);

            /** @var array{Barcode?: string} $body */
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (empty($body['Barcode'])) {
                throw new RuntimeException('PostNL returned no barcode in response.');
            }

            return $body['Barcode'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('PostNL barcode request failed: ' . $this->extractPostNLError($e), 0, $e);
        }
    }

    /**
     * Build a receiver array for {@see generateLabel()} from the configured PostNL sender (RD) address.
     * Used for return labels where the package is addressed to the warehouse.
     *
     * @return array{
     *     name: string,
     *     company: string,
     *     street: string,
     *     house_nr: string,
     *     house_nr_addition: null,
     *     postcode: string,
     *     city: string,
     *     country: string,
     *     email: null,
     *     phone: null,
     *     weight: int,
     * }
     */
    public function senderAddressToReceiverArray(int $weightGrams = 1000): array
    {
        /** @var array{company: string, street: string, house_nr: string, postcode: string, city: string, country?: string} $sender */
        $sender = config('postnl.sender');

        return [
            'name' => '',
            'company' => $sender['company'],
            'street' => $sender['street'],
            'house_nr' => $sender['house_nr'],
            'house_nr_addition' => null,
            'postcode' => $sender['postcode'],
            'city' => $sender['city'],
            'country' => strtoupper($sender['country'] ?? 'NL'),
            'email' => null,
            'phone' => null,
            'weight' => $weightGrams,
        ];
    }

    /**
     * Shipment AddressType 02 (sender on label) from the same shape as {@see generateLabel()} receiver.
     *
     * @param  array{name?: string, company?: string|null, street: string, house_nr: string, house_nr_addition?: string|null, postcode: string, city: string, country: string}  $address
     * @return array<string, string>
     */
    private function shipmentAddress02FromReceiverShape(array $address): array
    {
        return [
            'AddressType' => '02',
            'Name' => $address['name'] ?? '',
            'CompanyName' => $address['company'] ?? '',
            'Street' => $address['street'],
            'HouseNr' => $address['house_nr'],
            'HouseNrExt' => $address['house_nr_addition'] ?? '',
            'Zipcode' => $address['postcode'],
            'City' => $address['city'],
            'Countrycode' => strtoupper($address['country']),
        ];
    }

    /**
     * Default shipment AddressType 02: RD warehouse from config (matches historic minimal fields).
     *
     * @return array<string, string>
     */
    private function shipmentAddress02FromConfigSender(): array
    {
        /** @var array{company: string, street: string, house_nr: string, postcode: string, city: string, country: string} $sender */
        $sender = config('postnl.sender');

        return [
            'AddressType' => '02',
            'Name' => '',
            'CompanyName' => $sender['company'],
            'Street' => $sender['street'],
            'HouseNr' => $sender['house_nr'],
            'HouseNrExt' => '',
            'Zipcode' => $sender['postcode'],
            'City' => $sender['city'],
            'Countrycode' => $sender['country'],
        ];
    }

    /**
     * Generate a shipping label PDF (base64-encoded) and confirm the shipment.
     *
     * @param  array{name: string, company: string|null, street: string, house_nr: string, house_nr_addition: string|null, postcode: string, city: string, country: string, email: string|null, phone: string|null}  $receiver
     * @param  array{name?: string, company?: string|null, street: string, house_nr: string, house_nr_addition?: string|null, postcode: string, city: string, country: string}|null  $shipmentSenderAddress  When set, used as shipment AddressType 02 (sender on the label). Defaults to {@see config('postnl.sender')} (RD warehouse).
     * @return string  Base64-encoded PDF content
     * @throws RuntimeException
     */
    public function generateLabel(
        string $barcode,
        array $receiver,
        string $reference = '',
        string $remark = '',
        string $parcelType = 'parcel',
        bool $requireSignature = false,
        ?string $collectionDate = null,
        ?array $shipmentSenderAddress = null,
    ): string {
        $sender = config('postnl.sender');
        $isEu = $this->isEuCountry($receiver['country']);
        $segment = $isEu ? config('postnl.eu') : config('postnl.non_eu');

        $address02 = $shipmentSenderAddress !== null
            ? $this->shipmentAddress02FromReceiverShape($shipmentSenderAddress)
            : $this->shipmentAddress02FromConfigSender();

        $addresses = [
            [
                'AddressType' => '01',
                'Name'        => $receiver['name'],
                'CompanyName' => $receiver['company'] ?? '',
                'Street'      => $receiver['street'],
                'HouseNr'     => $receiver['house_nr'],
                'HouseNrExt'  => $receiver['house_nr_addition'] ?? '',
                'Zipcode'     => $receiver['postcode'],
                'City'        => $receiver['city'],
                'Countrycode' => strtoupper($receiver['country']),
            ],
            $address02,
        ];

        $contacts = [];
        if (!empty($receiver['email'])) {
            $contacts[] = ['ContactType' => '01', 'Email' => $receiver['email'], 'TelNr' => $receiver['phone'] ?? ''];
        }

        $productOptions = [];
        if ($requireSignature) {
            $productOptions[] = ['Characteristic' => '002', 'Option' => '025'];
        }

        $productCode = match (true) {
            ! $isEu             => '4945',
            $parcelType === 'mailbox' => '2928',
            default             => '3085',
        };

        $shipment = [
            'Addresses'                => $addresses,
            'Barcode'                  => $barcode,
            'CollectionTimeStampStart' => $collectionDate ? $collectionDate . ' 07:00:00' : '',
            'CollectionTimeStampEnd'   => $collectionDate ? $collectionDate . ' 18:00:00' : '',
            'Contacts'                 => $contacts,
            'Dimension'                => ['Weight' => (int) ($receiver['weight'] ?? 1000)],
            'ProductCodeDelivery'      => $productCode,
            'ProductOptions'           => $productOptions,
            'Reference'                => $reference,
            'Remark'                   => $remark,
        ];

        $payload = [
            'Customer' => [
                'Address' => [
                    'AddressType' => '09',
                    'CompanyName' => $sender['company'],
                    'Street'      => $sender['street'],
                    'HouseNr'     => $sender['house_nr'],
                    'Zipcode'     => $sender['postcode'],
                    'City'        => $sender['city'],
                    'Countrycode' => $sender['country'],
                ],
                'CollectionLocation' => config('postnl.collection_location'),
                'CustomerCode'       => $segment['customer_code'],
                'CustomerNumber'     => config('postnl.customer_number'),
            ],
            'Message' => [
                'MessageID'        => uniqid('rdm', true),
                'MessageTimeStamp' => now()->format('d-m-Y H:i:s'),
                'Printertype'      => 'GraphicFile|PDF',
            ],
            'Shipments' => [$shipment],
        ];

        try {
            $response = $this->client->post('/shipment/v2_2/label', [
                'query' => ['confirm' => 'true'],
                'json'  => $payload,
            ]);

            /** @var array{ResponseShipments?: list<array{Labels?: list<array{Content?: string}>}>} $body */
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $content = $body['ResponseShipments'][0]['Labels'][0]['Content'] ?? null;

            if (empty($content)) {
                throw new RuntimeException('PostNL returned no label content in response.');
            }

            return $content;
        } catch (GuzzleException $e) {
            throw new RuntimeException('Label niet aangemaakt: ' . $this->extractPostNLError($e), 0, $e);
        }
    }

    /**
     * Extract a human-readable error message from a PostNL API error response.
     * Falls back to the raw Guzzle message when the response body cannot be parsed.
     */
    private function extractPostNLError(GuzzleException $e): string
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            /** @var array{Errors?: list<array{Description?: string, ErrorMsg?: string}>}|null $decoded */
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                $errors = $decoded['Errors'] ?? [];
                $descriptions = array_filter(array_map(
                    fn (array $err): string => $err['Description'] ?? $err['ErrorMsg'] ?? '',
                    $errors,
                ));

                if (! empty($descriptions)) {
                    return implode(' | ', $descriptions);
                }
            }
        }

        return $e->getMessage();
    }

    /**
     * EU-27 ISO-2 country codes. Used to pick the correct PostNL product code and customer code.
     * VK, Zwitserland, Noorwegen etc. are treated as non-EU per PostNL contract.
     */
    private function isEuCountry(string $countryCode): bool
    {
        $euCodes = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
            'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
            'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        ];

        return in_array(strtoupper($countryCode), $euCodes, true);
    }
}

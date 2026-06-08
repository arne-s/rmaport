<?php

namespace App\Traits\Company;

use App\Models\Country;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

trait PostcodeValidatorTrait
{
    private static ?string $lastInvalidPostcodeNotificationKey = null;

    private static ?string $lastValidPostcodeNotificationKey = null;

    public static function validatePostcode($get, $set = null, ?Field $field = null, ?string $statePathPrefix = null): null|false|array
    {
        $fieldNames = [
            'postcode' => 'postcode',
            'house_number' => 'house_number',
            'house_number_addition' => 'house_number_addition',
            'country_id' => 'country_id',
            'street' => 'street',
            'city' => 'city',
        ];
        $prefix = $statePathPrefix;
        if ($prefix === null && ! empty($field)) {
            $prefix = $field->getStateRelationshipName();
        }
        if (! empty($prefix)) {
            foreach ($fieldNames as $key => $name) {
                $fieldNames[$key] = "{$prefix}.{$name}";
            }
        }

        $rawCountry = $get($fieldNames['country_id']);
        $countryIsNl = $rawCountry === null || (int) $rawCountry === Country::NL_ID;

        if (! $countryIsNl) {
            return null;
        }

        $pcLen = strlen((string) $get($fieldNames['postcode']));
        $hnEmpty = empty($get($fieldNames['house_number']));

        if ($pcLen < 6 || $hnEmpty) {
            // return null if data is not yet entered
            return null;
        }

        $postcodeService = app('postcode');
        $response = $postcodeService->fetchAddress(
            $get($fieldNames['postcode']),
            $get($fieldNames['house_number']),
            $get($fieldNames['house_number_addition']) ?? ''
        );

        if (! isset($response['street'])) {
            if ($set) {
                $set($fieldNames['city'], '');
                $set($fieldNames['street'], '');
                self::notifyInvalidPostcodeCombination($get, $fieldNames);
            }

            self::$lastValidPostcodeNotificationKey = null;

            return false;
        }

        self::$lastInvalidPostcodeNotificationKey = null;

        if ($set) {
            $set($fieldNames['city'], $response['city']);
            $set($fieldNames['street'], $response['street']);
            self::notifyValidPostcodeCombination($get, $fieldNames, $response);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected static function postcodeValidationExtraAttributes(Get $get, ?Field $field = null): array
    {
        $resp = self::validatePostcode($get, field: $field);

        if ($resp === null) {
            return [];
        }

        return ['class' => $resp === false ? 'invalid' : 'valid'];
    }

    /**
     * @param  array<string, string>  $fieldNames
     */
    private static function notifyInvalidPostcodeCombination($get, array $fieldNames): void
    {
        $key = sha1(implode('|', [
            (string) $get($fieldNames['postcode']),
            (string) $get($fieldNames['house_number']),
            (string) ($get($fieldNames['house_number_addition'] ?? '') ?? ''),
        ]));

        if (self::$lastInvalidPostcodeNotificationKey === $key) {
            return;
        }

        self::$lastInvalidPostcodeNotificationKey = $key;

        Notification::make()
            ->title('Ongeldige postcode')
            ->body('De combinatie postcode en huisnummer is niet gevonden. Controleer de gegevens.')
            ->danger()
            ->send();
    }

    /**
     * @param  array<string, string>  $fieldNames
     * @param  array<string, mixed>  $response
     */
    private static function notifyValidPostcodeCombination($get, array $fieldNames, array $response): void
    {
        $key = sha1(implode('|', [
            (string) $get($fieldNames['postcode']),
            (string) $get($fieldNames['house_number']),
            (string) ($get($fieldNames['house_number_addition'] ?? '') ?? ''),
            (string) ($response['street'] ?? ''),
            (string) ($response['city'] ?? ''),
        ]));

        if (self::$lastValidPostcodeNotificationKey === $key) {
            return;
        }

        self::$lastValidPostcodeNotificationKey = $key;

        Notification::make()
            ->title('Adres gevonden')
            ->body(sprintf(
                'Straat en plaats zijn ingevuld: %s, %s',
                $response['street'],
                $response['city'],
            ))
            ->success()
            ->duration(4000)
            ->send();
    }

}

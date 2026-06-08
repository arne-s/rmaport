<?php

namespace App\Filament\Forms;

use App\Models\Country;
use App\Traits\Company\PostcodeValidatorTrait;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class AddressFormSchema
{
    use PostcodeValidatorTrait;

    /**
     * @param  array{
     *     columnSpans?: array<string, int>,
     *     postcodeMaxLength?: int,
     * }  $options
     * @return array<\Filament\Forms\Components\Component>
     */
    public static function fields(array $options = []): array
    {
        $spans = array_merge([
            'postcode' => 5,
            'house_number' => 3,
            'house_number_addition' => 4,
            'street' => 4,
            'city' => 4,
            'country_id' => 4,
        ], $options['columnSpans'] ?? []);

        $maxPostcode = $options['postcodeMaxLength'] ?? 10;

        $postcodeValidationClass = fn (Get $get): array => match (self::validatePostcode($get)) {
            null => [],
            false => ['class' => 'invalid'],
            default => ['class' => 'valid'],
        };

        return [
            TextInput::make('postcode')
                ->required()
                ->label('Postcode')
                ->columnSpan($spans['postcode'])
                ->live(debounce: 1000)
                ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                ->afterStateUpdated(fn (Set $set, Get $get) => self::validatePostcode($get, $set))
                ->extraAttributes($postcodeValidationClass)
                ->maxLength($maxPostcode),

            TextInput::make('house_number')
                ->required()
                ->numeric()
                ->label('Nr.')
                ->columnSpan($spans['house_number'])
                ->live(debounce: 1000)
                ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                ->afterStateUpdated(fn (Set $set, Get $get) => self::validatePostcode($get, $set))
                ->extraAttributes($postcodeValidationClass)
                ->maxLength(255),

            TextInput::make('house_number_addition')
                ->label('Toevoeging')
                ->columnSpan($spans['house_number_addition'])
                ->extraAttributes($postcodeValidationClass)
                ->maxLength(255),

            TextInput::make('street')
                ->required()
                ->label('Straat')
                ->columnSpan($spans['street'])
                ->extraAttributes($postcodeValidationClass)
                ->maxLength(255),

            TextInput::make('city')
                ->required()
                ->label('Plaats')
                ->columnSpan($spans['city'])
                ->extraAttributes($postcodeValidationClass)
                ->maxLength(255),

            Select::make('country_id')
                ->label('Land')
                ->required()
                ->options(fn () => Country::query()->orderBy('name')->pluck('name', 'id'))
                ->default(Country::NL_ID)
                ->live(debounce: 1000)
                ->partiallyRenderComponentsAfterStateUpdated(['street', 'city'])
                ->extraAttributes($postcodeValidationClass)
                ->columnSpan($spans['country_id']),
        ];
    }
}

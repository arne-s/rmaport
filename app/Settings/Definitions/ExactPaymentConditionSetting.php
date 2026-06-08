<?php

namespace App\Settings\Definitions;

use App\Models\ExactPaymentCondition;
use App\Settings\SettingsDefaults;
use Filament\Forms\Components\Select;
use Illuminate\Validation\Rule;

class ExactPaymentConditionSetting extends AbstractSettingDefinition
{
    public function default(): mixed
    {
        return SettingsDefaults::defaultValue($this->uid());
    }

    public function rules(): array
    {
        return [
            'nullable',
            'string',
            Rule::in([...array_keys(ExactPaymentCondition::getPaymentConditionsAsOptions()), '']),
        ];
    }

    public function component(string $statePath): Select
    {
        return $this->applyCommonFieldAttributes(
            Select::make($statePath)
                ->options(ExactPaymentCondition::getPaymentConditionsAsOptions())
                ->placeholder('Standaard per klanttype')
                ->searchable()
                ->live(),
        );
    }

    public function serialize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function deserialize(?string $value): mixed
    {
        return $value ?? '';
    }

    public function getRuntime(?string $storedValue): mixed
    {
        return $storedValue ?? '';
    }
}

<?php

namespace App\Settings\Definitions;

use App\Models\ExactPaymentCondition;
use App\Settings\SettingsDefaults;
use Filament\Forms\Components\Select;
use Illuminate\Validation\Rule;

class ExactPaymentConditionByTypeSetting extends AbstractSettingDefinition
{
    public function default(): mixed
    {
        return SettingsDefaults::defaultValue($this->uid());
    }

    public function rules(): array
    {
        return [
            'required',
            'string',
            Rule::in(array_keys(ExactPaymentCondition::getPaymentConditionsAsOptions())),
        ];
    }

    public function component(string $statePath): Select
    {
        return $this->applyCommonFieldAttributes(
            Select::make($statePath)
                ->options(ExactPaymentCondition::getPaymentConditionsAsOptions())
                ->required()
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
        return $value ?? $this->default();
    }

    public function getRuntime(?string $storedValue): mixed
    {
        $value = $storedValue !== null && $storedValue !== ''
            ? $storedValue
            : (string) ($this->default() ?? ExactPaymentCondition::DEFAULT_PAYMENT_CONDITION_CODE);

        return $value;
    }
}

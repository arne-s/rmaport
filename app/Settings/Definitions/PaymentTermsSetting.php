<?php

namespace App\Settings\Definitions;

use App\Enums\PaymentTerms;
use App\Settings\SettingsDefaults;
use Filament\Forms\Components\Select;

class PaymentTermsSetting extends AbstractSettingDefinition
{
    public function default(): mixed
    {
        return SettingsDefaults::defaultValue($this->uid());
    }

    public function rules(): array
    {
        return ['required', 'string', 'in:' . implode(',', array_column(PaymentTerms::cases(), 'value'))];
    }

    /**
     * @return array<string, string>
     */
    public function validationMessages(): array
    {
        return [
            'value.required' => 'Selecteer een betalingsvoorwaarde.',
            'value.string' => 'Selecteer een betalingsvoorwaarde.',
            'value.in' => 'Selecteer een geldige betalingsvoorwaarde.',
        ];
    }

    public function component(string $statePath): Select
    {
        return $this->applyCommonFieldAttributes(
            Select::make($statePath)
                ->options(PaymentTerms::labels())
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
            : (string) ($this->default() ?? '');

        return $value;
    }
}

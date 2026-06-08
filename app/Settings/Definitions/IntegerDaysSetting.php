<?php

namespace App\Settings\Definitions;

use App\Settings\SettingsDefaults;
use Filament\Forms\Components\TextInput;

class IntegerDaysSetting extends AbstractSettingDefinition
{
    public function default(): mixed
    {
        return (int) SettingsDefaults::defaultValue($this->uid());
    }

    public function rules(): array
    {
        return ['required', 'integer', 'min:0', 'max:365'];
    }

    public function component(string $statePath): TextInput
    {
        return $this->applyCommonFieldAttributes(
            TextInput::make($statePath)
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(365)
                ->suffix('dagen')
                ->live(onBlur: true),
        );
    }

    public function serialize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) (int) $value;
    }

    public function deserialize(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return $this->default();
        }

        return (int) $value;
    }

    public function getRuntime(?string $storedValue): mixed
    {
        if ($storedValue === null || $storedValue === '') {
            return (int) $this->default();
        }

        return max(0, (int) $storedValue);
    }
}

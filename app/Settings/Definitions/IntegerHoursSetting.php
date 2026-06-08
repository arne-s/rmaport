<?php

namespace App\Settings\Definitions;

use App\Settings\SettingsDefaults;
use Filament\Forms\Components\TextInput;

class IntegerHoursSetting extends AbstractSettingDefinition
{
    public function default(): mixed
    {
        return (int) SettingsDefaults::defaultValue($this->uid());
    }

    public function rules(): array
    {
        return ['required', 'integer', 'min:1', 'max:999'];
    }

    public function component(string $statePath): TextInput
    {
        return $this->applyCommonFieldAttributes(
            TextInput::make($statePath)
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(999)
                ->suffix('uur')
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

        return max(1, (int) $storedValue);
    }
}

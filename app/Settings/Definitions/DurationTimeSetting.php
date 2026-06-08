<?php

namespace App\Settings\Definitions;

use App\Settings\SettingsDefaults;
use App\Support\DurationTime;
use Filament\Forms\Components\TextInput;

class DurationTimeSetting extends AbstractSettingDefinition
{
    public function default(): mixed
    {
        $seconds = (int) SettingsDefaults::defaultValue($this->uid());

        return DurationTime::secondsToDuration($seconds);
    }

    public function rules(): array
    {
        return ['required', 'regex:/^\d{1,2}:\d{2}$/'];
    }

    public function component(string $statePath): TextInput
    {
        return $this->applyCommonFieldAttributes(
            TextInput::make($statePath)
                ->placeholder('uu:mm')
                ->required()
                ->maxLength(5)
                ->live(onBlur: true)
                ->extraInputAttributes(['pattern' => '[0-9]{1,2}:[0-9]{2}', 'inputmode' => 'numeric']),
        );
    }

    public function serialize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return (string) DurationTime::durationToSeconds($value);
    }

    public function deserialize(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return $this->default();
        }

        return DurationTime::secondsToDuration((int) $value);
    }

    public function getRuntime(?string $storedValue): mixed
    {
        if ($storedValue === null || $storedValue === '') {
            return (int) SettingsDefaults::defaultValue($this->uid());
        }

        return max(0, (int) $storedValue);
    }
}

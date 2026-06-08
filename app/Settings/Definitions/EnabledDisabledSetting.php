<?php

namespace App\Settings\Definitions;

use App\Settings\SettingsDefaults;
use Filament\Forms\Components\Select;

class EnabledDisabledSetting extends AbstractSettingDefinition
{
    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ENABLED => 'Ingeschakeld',
            self::DISABLED => 'Uitgeschakeld',
        ];
    }

    public static function isEnabled(mixed $value): bool
    {
        return self::normalize($value) === self::ENABLED;
    }

    public static function normalize(mixed $value): string
    {
        return $value === self::DISABLED ? self::DISABLED : self::ENABLED;
    }

    public function default(): mixed
    {
        return SettingsDefaults::defaultValue($this->uid()) ?? self::ENABLED;
    }

    public function rules(): array
    {
        return ['required', 'string', 'in:' . self::ENABLED . ',' . self::DISABLED];
    }

    public function component(string $statePath): Select
    {
        return $this->applyCommonFieldAttributes(
            Select::make($statePath)
                ->options(self::labels())
                ->required()
                ->native(false)
                ->live(),
        );
    }

    public function serialize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::normalize($value);
    }

    public function deserialize(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return $this->default();
        }

        return self::normalize($value);
    }

    public function getRuntime(?string $storedValue): mixed
    {
        if ($storedValue === null || $storedValue === '') {
            return self::normalize($this->default());
        }

        return self::normalize($storedValue);
    }
}

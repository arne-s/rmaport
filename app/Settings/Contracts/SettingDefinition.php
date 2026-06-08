<?php

namespace App\Settings\Contracts;

use Filament\Forms\Components\Field;

interface SettingDefinition
{
    public function uid(): string;

    public function default(): mixed;

    /**
     * @return array<string|int, mixed>
     */
    public function rules(): array;

    public function component(string $statePath): Field;

    public function serialize(mixed $value): ?string;

    public function deserialize(?string $value): mixed;

    public function getRuntime(?string $storedValue): mixed;
}

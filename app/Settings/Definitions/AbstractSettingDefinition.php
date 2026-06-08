<?php

namespace App\Settings\Definitions;

use App\Models\Setting;
use App\Settings\Contracts\SettingDefinition;
use Filament\Forms\Components\Field;
use Livewire\Component;

abstract class AbstractSettingDefinition implements SettingDefinition
{
    public function __construct(protected Setting $setting) {}

    public function uid(): string
    {
        return $this->setting->uid;
    }

    protected function label(): string
    {
        return $this->setting->name;
    }

    protected function helperText(): ?string
    {
        return $this->setting->description;
    }

    abstract public function default(): mixed;

    /**
     * @return array<string|int, mixed>
     */
    abstract public function rules(): array;

    abstract public function component(string $statePath): Field;

    abstract public function serialize(mixed $value): ?string;

    abstract public function deserialize(?string $value): mixed;

    abstract public function getRuntime(?string $storedValue): mixed;

    protected function applyCommonFieldAttributes(Field $field): Field
    {
        $field->label($this->label());

        if ($this->helperText() !== null && $this->helperText() !== '') {
            $field->helperText($this->helperText());
        }

        $uid = $this->uid();

        return $field
            ->inlineLabel()
            ->columnSpan(3)
            ->dehydrated(false)
            ->afterStateUpdated(function (mixed $state, Component $livewire, mixed $old) use ($uid): void {
                if ($old == $state) {
                    return;
                }

                if (method_exists($livewire, 'saveSetting')) {
                    $livewire->saveSetting($uid, $state);
                }
            });
    }
}

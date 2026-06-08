<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Concerns\HasPlaceholder;
use Filament\Forms\Components\Field;

class EmailRecipientSelect extends Field
{
    use HasPlaceholder;

    protected string $view = 'filament.forms.resources.components.email-recipient-select';

    /** @var array<string, string>|Closure */
    protected array|Closure $options = [];

    /** @var array<int, string>|Closure */
    protected array|Closure $lockedValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
    }

    /**
     * @param  array<string, string>|Closure  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->evaluate($this->options);
    }

    /**
     * Recipient keys that must stay selected (e.g. dealer Levergegevens on release-order mail).
     *
     * @param  array<int, string>|Closure  $lockedValues
     */
    public function lockedValues(array|Closure $lockedValues): static
    {
        $this->lockedValues = $lockedValues;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getLockedValues(): array
    {
        return array_values($this->evaluate($this->lockedValues));
    }
}

<?php

namespace App\Filament\Forms\Components;

use Filament\Schemas\Components\Component;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

class ToggleFilter extends Component
{
    protected string $view = 'filament.forms.resources.components.toggle-filter';

    protected string | Htmlable | Closure | null $label = null;

    final public function __construct(string | Htmlable | Closure | null $label = null)
    {
        $this->label($label);
    }

    public function label(string | Htmlable | Closure | null $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getLabel(): string | Htmlable | Closure | null
    {
        return $this->evaluate($this->label);
    }

    public static function make(string | Htmlable | Closure | null $label = null): static
    {
        $static = app(static::class, ['label' => $label]);
        $static->configure();

        return $static;
    }
}

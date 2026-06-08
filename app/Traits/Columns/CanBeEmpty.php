<?php

namespace App\Traits\Columns;

use Closure;

trait CanBeEmpty
{
    protected bool | Closure $shouldLeaveEmpty = false;

    public function empty(bool | Closure $condition = true): static
    {
        $this->shouldLeaveEmpty = $condition;

        return $this;
    }

    public function shouldLeaveEmpty(): bool
    {
        return $this->evaluate($this->shouldLeaveEmpty);
    }
}

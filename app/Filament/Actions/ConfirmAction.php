<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Closure;

class ConfirmAction extends Action
{
    protected static int $counter = 0;

    protected string $confirmModalTitle = 'Let op!';
    protected string $confirmModalDescription = '';
    protected string $confirmButtonLabel = 'Verwijderen & opslaan';
    protected string $confirmButtonClass = '';
    protected ?Closure $onConfirm = null;

    public static function getDefaultName(): ?string
    {
        return 'confirm_action_' . (++self::$counter);
    }

    public function setModalTitle(string $title): static
    {
        $this->confirmModalTitle = $title;
        return $this;
    }

    public function setModalDescription(string $description): static
    {
        $this->confirmModalDescription = $description;
        return $this;
    }

    public function setConfirmButtonLabel(string $label): static
    {
        $this->confirmButtonLabel = $label;
        return $this;
    }

    public function setConfirmButtonClass(string $class): static
    {
        $this->confirmButtonClass = $class;
        return $this;
    }

    public function onConfirm(Closure $callback): static
    {
        $this->onConfirm = $callback;
        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No-op action callback; the real behaviour is handled via JavaScript
        $this->action(function () {
            // Not run when JavaScript opens the modal instead
        });
    }

    public function getExtraAttributes(): array
    {
        $attributes = [
            'data-confirm-modal-title' => $this->confirmModalTitle,
            'data-confirm-modal-description' => $this->confirmModalDescription,
            'data-confirm-button-label' => $this->confirmButtonLabel,
            'x-on:click' => 'openConfirmModal($event, $el)',
        ];

        if ($this->confirmButtonClass) {
            $attributes['data-confirm-button-class'] = $this->confirmButtonClass;
        }

        return $attributes;
    }

    public function getModalTitle(): string
    {
        return $this->confirmModalTitle;
    }

    public function getModalDescription(): string
    {
        return $this->confirmModalDescription;
    }

    public function getConfirmButtonLabel(): string
    {
        return $this->confirmButtonLabel;
    }

    public function getConfirmButtonClass(): string
    {
        return $this->confirmButtonClass;
    }
}


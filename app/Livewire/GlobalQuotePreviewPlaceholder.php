<?php

namespace App\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\On;
use Livewire\Component;

class GlobalQuotePreviewPlaceholder extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function quote_preview_placeholderAction(): Action
    {
        return Action::make('quote_preview_placeholder')
            ->modalHeading('Offerte PDF')
            ->modalDescription('PDF placeholder: de offerte-PDF is nog niet beschikbaar.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten');
    }

    #[On('open-quote-preview-placeholder')]
    public function openQuotePreviewPlaceholderModal(): void
    {
        $this->mountAction('quote_preview_placeholder');
    }

    public function render()
    {
        return view('livewire.global-quote-preview-placeholder');
    }
}

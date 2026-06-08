<?php

namespace App\Http\Livewire;

use App\Models\MicrosoftMailToken;
use App\Services\MicrosoftMailService;
use Livewire\Component;

class MicrosoftMailConnect extends Component
{
    public int $tokenId;

    public ?MicrosoftMailToken $token = null;

    public function mount(): void
    {
        $this->token = MicrosoftMailToken::find($this->tokenId);
    }

    public function setDefault(): void
    {
        $token = MicrosoftMailToken::find($this->tokenId);
        if ($token) {
            $token->setAsDefault();
            $this->token = $token->fresh();
        }
    }

    public function unsetDefault(): void
    {
        $token = MicrosoftMailToken::find($this->tokenId);
        if ($token) {
            $token->update(['is_default' => false]);
            $this->token = $token->fresh();
        }
    }

    public function disconnect(): void
    {
        app(MicrosoftMailService::class)->disconnect($this->tokenId);

        $url = route('filament.app.resources.customers.settings').'?area=outlook-mail';

        $this->redirect(
            $url,
            navigate: false,
        );
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.microsoft-mail-connect');
    }
}

<?php

namespace App\Http\Livewire;

use App\Models\MailSenderProfile;
use App\Models\MicrosoftMailToken;
use Livewire\Attributes\On;
use Livewire\Component;

class MicrosoftMailSenderProfiles extends Component
{
    /** @var array<int, int|null>  profile_id → microsoft_mail_token_id */
    public array $mappings = [];

    /** @var array<int, array{id: int, label: string}> */
    public array $tokens = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $profiles = [];

    public bool $saved = false;

    public function mount(): void
    {
        $this->tokens = MicrosoftMailToken::orderBy('microsoft_email')
            ->get()
            ->map(fn (MicrosoftMailToken $t) => [
                'id'    => $t->id,
                'label' => $t->microsoft_email ?? ('Account ' . $t->id),
            ])
            ->all();

        $this->profiles = MailSenderProfile::orderBy('name')
            ->get()
            ->map(fn (MailSenderProfile $p) => [
                'id'   => $p->id,
                'name' => $p->name,
            ])
            ->all();

        $existing = MailSenderProfile::all()->keyBy('id');

        foreach ($this->profiles as $profile) {
            $row = $existing[$profile['id']] ?? null;
            $this->mappings[$profile['id']] = $row?->microsoft_mail_token_id;
        }
    }

    #[On('profile-saved')]
    public function save(): void
    {
        foreach ($this->profiles as $profile) {
            $tokenId = $this->mappings[$profile['id']] ?? null;

            MailSenderProfile::where('id', $profile['id'])->update([
                'microsoft_mail_token_id' => $tokenId ?: null,
            ]);
        }

        $this->saved = true;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.microsoft-mail-sender-profiles');
    }
}

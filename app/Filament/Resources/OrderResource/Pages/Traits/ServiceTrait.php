<?php

namespace App\Filament\Resources\OrderResource\Pages\Traits;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Validator;

/**
 * Service/Onderhoud tab note fields: subset of FittingTrait without previous-unit and previous-unit-note fields.
 */
trait ServiceTrait
{
    public string $serviceNoteAttendees = '';
    public string $serviceNoteAdvisorDealerName = '';
    public string $serviceNoteAdvisorDealerEmail = '';
    public string $serviceNoteBirthDate = '';
    public string $serviceNoteBodyLength = '';
    public string $serviceNoteBodyWeight = '';
    public string $serviceNoteHandicap = '';
    public string $serviceNoteGeneralNotes = '';

    public function loadServiceFields(): void
    {
        $note = $this->record->getServiceNote();
        if (is_array($note)) {
            $this->serviceNoteAttendees = (string) ($note['attendees'] ?? '');
            $this->serviceNoteAdvisorDealerName = (string) ($note['advisor_dealer_name'] ?? '');
            $this->serviceNoteAdvisorDealerEmail = (string) ($note['advisor_dealer_email'] ?? '');
            $this->serviceNoteBirthDate = (string) ($note['birth_date'] ?? '');
            $this->serviceNoteBodyLength = (string) ($note['body_length'] ?? '');
            $this->serviceNoteBodyWeight = (string) ($note['body_weight'] ?? '');
            $this->serviceNoteHandicap = (string) ($note['handicap'] ?? '');
            $this->serviceNoteGeneralNotes = (string) ($note['general_notes'] ?? '');
        }
    }

    public function saveServiceFields(): bool
    {
        if ($this->record === null) {
            return true;
        }

        $emailTrimmed = trim($this->serviceNoteAdvisorDealerEmail);
        if ($emailTrimmed !== '') {
            $validator = Validator::make(
                ['advisor_dealer_email' => $emailTrimmed],
                ['advisor_dealer_email' => ['email']],
                ['advisor_dealer_email.email' => 'Adviseur dealer mail is geen geldig e-mailadres.']
            );
            if ($validator->fails()) {
                Notification::make()
                    ->title('Ongeldig e-mailadres')
                    ->body($validator->errors()->first('advisor_dealer_email'))
                    ->danger()
                    ->send();

                return false;
            }
        }

        $serviceNote = array_filter([
            'attendees' => $this->serviceNoteAttendees !== '' ? $this->serviceNoteAttendees : null,
            'advisor_dealer_name' => trim($this->serviceNoteAdvisorDealerName) !== '' ? trim($this->serviceNoteAdvisorDealerName) : null,
            'advisor_dealer_email' => $emailTrimmed !== '' ? $emailTrimmed : null,
            'birth_date' => $this->serviceNoteBirthDate !== '' ? $this->serviceNoteBirthDate : null,
            'body_length' => trim($this->serviceNoteBodyLength) !== '' ? trim($this->serviceNoteBodyLength) : null,
            'body_weight' => trim($this->serviceNoteBodyWeight) !== '' ? trim($this->serviceNoteBodyWeight) : null,
            'handicap' => $this->serviceNoteHandicap !== '' ? $this->serviceNoteHandicap : null,
            'general_notes' => $this->serviceNoteGeneralNotes !== '' ? $this->serviceNoteGeneralNotes : null,
        ], fn ($v) => $v !== null && $v !== '');

        $this->record->setServiceNote($serviceNote !== [] ? $serviceNote : null);

        return true;
    }
}

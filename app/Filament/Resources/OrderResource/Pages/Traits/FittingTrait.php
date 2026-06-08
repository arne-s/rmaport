<?php

namespace App\Filament\Resources\OrderResource\Pages\Traits;

use App\Models\SerialNumber;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Validator;

trait FittingTrait
{
    /** Value for "Specify yourself" in the previous chair make/model dropdown. */
    public const FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE = '__custom__';

    /** Fitting note JSON fields (all optional). */
    public string $fittingNoteAttendees = '';
    public string $fittingNoteAdvisorDealerName = '';
    public string $fittingNoteAdvisorDealerMobile = '';
    public string $fittingNoteAdvisorDealerEmail = '';
    public string $fittingNoteBirthDate = '';
    public string $fittingNoteBodyLength = '';
    public string $fittingNoteBodyWeight = '';
    public string $fittingNoteHandicap = '';
    public string $fittingNotePreviousUnit = '';

    /** Custom text when "Specify yourself" is selected for previous chair make/model. */
    public string $fittingNotePreviousUnitCustom = '';
    public string $fittingNotePreviousUnitNote = '';
    public string $fittingNoteGeneralNotes = '';

    /** Reason for cancellation in FittingCancelled modal. */
    public string $fittingCancelledReason = '';

    public function loadFittingFields(): void
    {
        $note = $this->record->getFittingNote();
        if (is_array($note)) {
            $this->fittingNoteAttendees = (string)($note['attendees'] ?? '');
            $this->fittingNoteAdvisorDealerName = (string)($note['advisor_dealer_name'] ?? '');
            $this->fittingNoteAdvisorDealerMobile = (string)($note['advisor_dealer_mobile'] ?? '');
            $this->fittingNoteAdvisorDealerEmail = (string)($note['advisor_dealer_email'] ?? '');
            $this->fittingNoteBirthDate = (string)($note['birth_date'] ?? '');
            $this->fittingNoteBodyLength = (string)($note['body_length'] ?? '');
            $this->fittingNoteBodyWeight = (string)($note['body_weight'] ?? '');
            $this->fittingNoteHandicap = (string)($note['handicap'] ?? '');

            if (!empty($note['previous_unit_custom'])) {
                $this->fittingNotePreviousUnit = self::FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE;
                $this->fittingNotePreviousUnitCustom = (string)$note['previous_unit_custom'];
                $this->fittingNotePreviousUnitNote = (string)($note['previous_unit_note'] ?? '');
            } else {
                $this->fittingNotePreviousUnit = (isset($note['previous_unit']) ? (string)$note['previous_unit'] : '');
                $this->fittingNotePreviousUnitNote = '';
            }
            $this->fittingNoteGeneralNotes = (string)($note['general_notes'] ?? '');
        }
    }

    public function updatedFittingNotePreviousUnit(mixed $value): void
    {
        if ((string) $value !== self::FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE) {
            $this->fittingNotePreviousUnitNote = '';
        }
    }

    /**
     * Serial number options for fitting note "Previous chair make/model".
     *
     * @return array<int, string>
     */
    public function getFittingNoteSerialNumberOptions(): array
    {
        $record = $this->record ?? null;
        if (!$record || !$record->customer_id) {
            return [];
        }

        return SerialNumber::query()
            ->where('owner_id', $record->customer_id)
            ->whereHas('order', function ($query): void {
                $query->where('type', 'order');
            })
            ->orderBy('updated_at', 'desc')
            ->get()
            ->mapWithKeys(fn(SerialNumber $sn) => [$sn->id => $sn->serial_number . ' | ' . $sn->getFrameName()])
            ->all();
    }

    public function saveFittingFields(): bool
    {
        if ($this->record === null) {
            return true;
        }

        $emailTrimmed = trim($this->fittingNoteAdvisorDealerEmail);
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

        $this->record->setSubtype($this->orderSubtype !== '' ? $this->orderSubtype : null);
        $this->record->setReference($this->orderReference !== '' ? $this->orderReference : null);
        $this->record->setReferenceInternal($this->referenceInternal !== '' ? $this->referenceInternal : null);

        $isPreviousUnitCustom = $this->fittingNotePreviousUnit === self::FITTING_NOTE_PREVIOUS_UNIT_CUSTOM_VALUE;
        $fittingNote = array_filter([
            'attendees'           => $this->fittingNoteAttendees !== '' ? $this->fittingNoteAttendees : null,
            'advisor_dealer_name' => trim($this->fittingNoteAdvisorDealerName) !== '' ? trim($this->fittingNoteAdvisorDealerName) : null,
            'advisor_dealer_mobile' => trim($this->fittingNoteAdvisorDealerMobile) !== '' ? trim($this->fittingNoteAdvisorDealerMobile) : null,
            'advisor_dealer_email' => $emailTrimmed !== '' ? $emailTrimmed : null,
            'birth_date'          => $this->fittingNoteBirthDate !== '' ? $this->fittingNoteBirthDate : null,
            'body_length'         => trim($this->fittingNoteBodyLength) !== '' ? trim($this->fittingNoteBodyLength) : null,
            'body_weight'         => trim($this->fittingNoteBodyWeight) !== '' ? trim($this->fittingNoteBodyWeight) : null,
            'handicap'            => $this->fittingNoteHandicap !== '' ? $this->fittingNoteHandicap : null,
            'previous_unit'       => !$isPreviousUnitCustom && $this->fittingNotePreviousUnit !== '' ? (int)$this->fittingNotePreviousUnit : null,
            'previous_unit_custom' => $isPreviousUnitCustom && $this->fittingNotePreviousUnitCustom !== '' ? $this->fittingNotePreviousUnitCustom : null,
            'previous_unit_note'  => $isPreviousUnitCustom && $this->fittingNotePreviousUnitNote !== '' ? $this->fittingNotePreviousUnitNote : null,
            'general_notes'       => $this->fittingNoteGeneralNotes !== '' ? $this->fittingNoteGeneralNotes : null,
        ], fn($v) => $v !== null && $v !== '');
        $this->record->setFittingNote($fittingNote !== [] ? $fittingNote : null);

        return true;
    }
}

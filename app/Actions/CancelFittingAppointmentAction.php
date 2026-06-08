<?php

namespace App\Actions;

use App\Enums\AppointmentType;
use App\Models\Appointment;
use App\Models\MicrosoftToken;
use App\Models\Order\Main;
use App\Services\MicrosoftCalendarService;
use App\Support\OutlookEventIds;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CancelFittingAppointmentAction
{
    public function __construct(protected MicrosoftCalendarService $calendarService)
    {
    }

    public function execute(Main $main): void
    {
        $appointments = Appointment::query()
            ->where('order_id', $main->getId())
            ->where('type', AppointmentType::Fitting)
            ->where('is_active', true)
            ->with(['advisors', 'mechanics'])
            ->get();

        if ($appointments->isEmpty()) {
            return;
        }

        $advisorToken = MicrosoftToken::resolveForRoleName('advisor');
        $mechanicToken = MicrosoftToken::resolveForRoleName('mechanic');

        foreach ($appointments as $appointment) {
            $this->cancelAppointment($main, $appointment, $advisorToken, $mechanicToken);
        }

        $main->orderEvents()->create([
            'type' => 'Passing-afspraak geannuleerd en uit agenda verwijderd',
            'data' => [
                'appointment_ids' => $appointments->pluck('id')->values()->all(),
            ],
            'user_id' => Auth::id(),
        ]);
    }

    public function executeForAppointment(Main $main, Appointment $appointment): void
    {
        if (! $appointment->is_active) {
            return;
        }

        $appointment->loadMissing(['advisors', 'mechanics']);

        $advisorToken = MicrosoftToken::resolveForRoleName('advisor');
        $mechanicToken = MicrosoftToken::resolveForRoleName('mechanic');

        $this->cancelAppointment($main, $appointment, $advisorToken, $mechanicToken);
    }

    private function cancelAppointment(
        Main $main,
        Appointment $appointment,
        ?MicrosoftToken $advisorToken,
        ?MicrosoftToken $mechanicToken,
    ): void {
        foreach (OutlookEventIds::collect($appointment->outlook_event_ids, $appointment->outlook_event_id) as $eventId) {
            $this->deleteEvent($advisorToken, $eventId, $main, $appointment);
        }

        foreach ($appointment->advisors as $advisor) {
            foreach (OutlookEventIds::collect($advisor->pivot->outlook_event_ids ?? null, $advisor->pivot->outlook_event_id ?? null) as $eventId) {
                $this->deleteEvent($advisorToken, $eventId, $main, $appointment);
            }
        }

        foreach ($appointment->mechanics as $mechanic) {
            foreach (OutlookEventIds::collect($mechanic->pivot->outlook_event_ids ?? null, $mechanic->pivot->outlook_event_id ?? null) as $eventId) {
                $this->deleteEvent($mechanicToken, $eventId, $main, $appointment);
            }
        }

        $appointment->update([
            'is_active'         => false,
            'outlook_event_id'  => null,
            'outlook_event_ids' => null,
        ]);
    }

    private function deleteEvent(?MicrosoftToken $token, ?string $eventId, Main $main, Appointment $appointment): void
    {
        if (! filled($eventId) || $token === null) {
            return;
        }

        try {
            $this->calendarService->deleteEvent($token->id, $eventId);
        } catch (\Throwable $e) {
            Log::error('CancelFittingAppointmentAction: Outlook verwijderen mislukt', [
                'main_id' => $main->getId(),
                'appointment_id' => $appointment->id,
                'outlook_event_id' => $eventId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

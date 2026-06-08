<?php

namespace App\Actions;

use App\Enums\AppointmentType;
use App\Models\Appointment;
use App\Models\Order\Main;
use App\Models\User;
use App\View\Components\PanelNotification;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Auth;

class NotifyAdvisorFittingCancelledAction
{
    public function execute(Main $main, ?string $reason = null): void
    {
        $advisor = $main->advisor;
        if (! $advisor instanceof User) {
            return;
        }

        $appointment = $main->appointments()
            ->where('type', AppointmentType::Fitting)
            ->latest('created_at')
            ->first();

        $uid = $main->getUidFormatted() ?: (string) $main->getId();
        $body = 'Aanvraag: #' . e($uid);

        if ($appointment instanceof Appointment) {
            $body .= '<br>Datum/Tijdstip: ' . e($appointment->getDatetime()->translatedFormat('d-m-Y H:i'));
        }

        $cancelledBy = Auth::user();
        if ($cancelledBy instanceof User) {
            $body .= '<br>Geannuleerd door: ' . e($cancelledBy->getName());
        }

        $trimmedReason = trim((string) $reason);
        if ($trimmedReason !== '') {
            $body .= '<br>Reden: ' . e($trimmedReason);
        }

        PanelNotification::make()
            ->title('Passing geannuleerd')
            ->icon('heroicon-s-calendar')
            ->body($body)
            ->actions([
                Action::make('open')
                    ->alpineClickHandler("window.location.href='" . route('filament.app.resources.mains.view', [
                        'record' => $main->getId(),
                        'tab' => 'fitting',
                    ]) . "'"),
            ])
            ->sendToDatabase($advisor);
    }
}

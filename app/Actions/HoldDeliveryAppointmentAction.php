<?php

namespace App\Actions;

use App\Enums\AppointmentType;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Order\Main;
use Illuminate\Support\Facades\Auth;

class HoldDeliveryAppointmentAction
{
    public function __construct(protected CancelFittingAppointmentAction $cancelFittingAppointmentAction)
    {
    }

    public function execute(Main $main, string $reason): void
    {
        if ($main->is_completed) {
            return;
        }

        $reason = trim($reason);

        if ($reason === '') {
            return;
        }

        $appointment = Appointment::query()
            ->where('order_id', $main->getId())
            ->where('type', AppointmentType::Delivery)
            ->where('is_active', true)
            ->first();

        if ($appointment === null) {
            return;
        }

        $appointment->update(['comment' => $reason]);

        $this->cancelFittingAppointmentAction->executeForAppointment($main, $appointment);

        $main->changeOrderStatus(OrderStatus::DeliveryOnHold);

        // Cancellation notifications ignore notify_advisor / notify_workshop / notify_customer on the appointment.
        app(SendDeliveryCancelledMailAction::class)->execute($main, $reason, $appointment);

        $main->orderEvents()->create([
            'type'    => 'Levering on hold: opnieuw inplannen',
            'data'    => [
                'appointment_id' => $appointment->id,
                'reason'         => $reason,
            ],
            'user_id' => Auth::id(),
        ]);
    }
}

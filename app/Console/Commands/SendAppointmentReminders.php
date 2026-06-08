<?php

namespace App\Console\Commands;

use App\Actions\SendDeliveryReminderAdvisorMailAction;
use App\Actions\SendDeliveryReminderMailAction;
use App\Actions\SendFittingReminderAdvisorMailAction;
use App\Actions\SendFittingReminderMailAction;
use App\Actions\SendServiceReminderAdvisorMailAction;
use App\Actions\SendServiceReminderMailAction;
use App\Actions\SendServiceReminderMechanicMailAction;
use App\Enums\AppointmentType;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Order\Main;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Stuur herinneringsmails 24 uur voor een afspraak (elk uur uitvoeren).';

    public function handle(
        SendFittingReminderMailAction $fittingAction,
        SendDeliveryReminderMailAction $deliveryAction,
        SendServiceReminderMailAction $serviceAction,
        SendFittingReminderAdvisorMailAction $fittingAdvisorAction,
        SendDeliveryReminderAdvisorMailAction $deliveryAdvisorAction,
        SendServiceReminderAdvisorMailAction $serviceAdvisorAction,
        SendServiceReminderMechanicMailAction $serviceMechanicReminderAction,
    ): int {
        /** Send reminders for appointments within the next 25 hours that have not yet been sent. */
        $window = now()->addHours(25);

        $appointments = Appointment::query()
            ->whereNull('reminder_sent_at')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->where(function ($query): void {
                $query->where('notify_customer', true)
                    ->orWhere('notify_advisor', true)
                    ->orWhere('notify_workshop', true);
            })
            ->whereNotNull('customer_datetime_start')
            ->where('customer_datetime_start', '>', now())
            ->where('customer_datetime_start', '<=', $window)
            ->where('created_at', '<=', now()->subHours(24))
            ->with(['advisors', 'mechanics'])
            ->get();

        if ($appointments->isEmpty()) {
            $this->info('Geen herinneringen te versturen.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($appointments as $appointment) {
            $main = Main::query()
                ->with(['customer', 'billingCustomer', 'advisor'])
                ->find($appointment->order_id);

            if (! $main instanceof Main) {
                $this->warn("Afspraak #{$appointment->id}: hoofdorder niet gevonden, overgeslagen.");
                continue;
            }

            $status = $main->getOrderStatus();
            if ($status !== null && in_array($status, [
                OrderStatus::Cancelled,
                OrderStatus::FittingCancelled,
                OrderStatus::QuoteCancelled,
            ], true)) {
                $this->line("Afspraak #{$appointment->id}: order geannuleerd ({$status->value}), herinnering overgeslagen.");
                $appointment->update(['reminder_sent_at' => now()]);
                continue;
            }

            try {
                if ($appointment->type === AppointmentType::Fitting) {
                    $this->sendFittingReminders(
                        $appointment,
                        $main,
                        $fittingAction,
                        $fittingAdvisorAction,
                    );
                } elseif ($appointment->type === AppointmentType::Delivery) {
                    $this->sendDeliveryReminders(
                        $appointment,
                        $main,
                        $deliveryAction,
                        $deliveryAdvisorAction,
                    );
                } elseif ($appointment->type === AppointmentType::Service) {
                    $this->sendServiceReminders(
                        $appointment,
                        $main,
                        $serviceAction,
                        $serviceAdvisorAction,
                        $serviceMechanicReminderAction,
                    );
                } else {
                    continue;
                }

                $appointment->update(['reminder_sent_at' => now()]);
                $sent++;

                $this->line("Herinnering verstuurd: afspraak #{$appointment->id} ({$appointment->type->value}) – {$appointment->datetime}");
            } catch (\Throwable $e) {
                $this->error("Afspraak #{$appointment->id}: fout bij versturen – {$e->getMessage()}");
            }
        }

        $this->info("{$sent} herinnering(en) verstuurd.");

        return self::SUCCESS;
    }

    private function sendFittingReminders(
        Appointment $appointment,
        Main $main,
        SendFittingReminderMailAction $customerAction,
        SendFittingReminderAdvisorMailAction $advisorAction,
    ): void {
        if ($appointment->notify_customer) {
            $customerAction->execute($main);
        }

        if ($appointment->notify_advisor) {
            foreach ($appointment->advisors as $advisor) {
                $advisorAction->execute($main, $advisor);
            }
        }

        if ($appointment->notify_workshop) {
            $notifiedAdvisorIds = $appointment->notify_advisor
                ? $appointment->advisors->pluck('id')->flip()->all()
                : [];

            foreach ($appointment->mechanics as $mechanic) {
                if (isset($notifiedAdvisorIds[$mechanic->getKey()])) {
                    continue;
                }

                $advisorAction->execute($main, $mechanic);
            }
        }
    }

    private function sendDeliveryReminders(
        Appointment $appointment,
        Main $main,
        SendDeliveryReminderMailAction $customerAction,
        SendDeliveryReminderAdvisorMailAction $advisorAction,
    ): void {
        if ($appointment->notify_customer) {
            $customerAction->execute($main);
        }

        if ($appointment->notify_advisor) {
            foreach ($appointment->advisors as $advisor) {
                $advisorAction->execute($main, $advisor);
            }
        }

        if ($appointment->notify_workshop) {
            $notifiedAdvisorIds = $appointment->notify_advisor
                ? $appointment->advisors->pluck('id')->flip()->all()
                : [];

            foreach ($appointment->mechanics as $mechanic) {
                if (isset($notifiedAdvisorIds[$mechanic->getKey()])) {
                    continue;
                }

                $advisorAction->execute($main, $mechanic);
            }
        }
    }

    private function sendServiceReminders(
        Appointment $appointment,
        Main $main,
        SendServiceReminderMailAction $customerAction,
        SendServiceReminderAdvisorMailAction $advisorAction,
        SendServiceReminderMechanicMailAction $mechanicAction,
    ): void {
        if ($appointment->notify_customer) {
            $customerAction->execute($main);
        }

        if ($appointment->notify_advisor) {
            foreach ($appointment->advisors as $advisor) {
                $advisorAction->execute($main, $advisor);
            }
        }

        if ($appointment->notify_workshop) {
            foreach ($appointment->mechanics as $mechanic) {
                $mechanicAction->execute($main, $mechanic);
            }
        }
    }
}

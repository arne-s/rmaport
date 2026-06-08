<?php

namespace App\Actions;

use App\Enums\AppointmentType;
use App\Enums\OrderSubtype;
use App\Mail\Unit\ServiceCancelledMail;
use App\Models\Appointment;
use App\Models\EmailTemplate;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceCancelledMailAction
{
    public function __construct(protected OrderMailEventLogger $logger)
    {
    }

    /**
     * Always notify every assigned advisor and mechanic on the cancelled appointment.
     * notify_advisor / notify_workshop on the appointment are intentionally ignored.
     */
    public function execute(BaseOrder $order, ?string $reason = null, ?Appointment $cancelledAppointment = null): void
    {
        $subtype = $order->main?->getSubtype() ?? $order->getSubtype() ?? OrderSubtype::Unit;

        if (! in_array($subtype, [OrderSubtype::Unit, OrderSubtype::Service], true)) {
            return;
        }

        $main = $order instanceof Main ? $order : $order->main;
        if ($main === null) {
            return;
        }

        $template = EmailTemplate::query()->where('class', ServiceCancelledMail::class)->first();
        if ($template === null) {
            Log::warning('ServiceCancelledMail: e-mailtemplate niet gevonden (class '.ServiceCancelledMail::class.').');

            return;
        }

        $recipients = $this->resolveCancelledAppointmentRecipients($main, $cancelledAppointment);
        $sentTo = [];

        foreach ($recipients as $recipient) {
            $mailable = new ServiceCancelledMail($main, $recipient, $reason);
            [$toEmail, $toName] = $mailable->resolveRecipient();

            if ($toEmail === null) {
                continue;
            }

            try {
                Mail::send($mailable);
                $sentTo[] = ['email' => $toEmail, 'name' => $toName];
            } catch (\Throwable $e) {
                Log::error('ServiceCancelledMail: verzenden mislukt: '.$e->getMessage(), [
                    'order_id' => $main->getId(),
                    'user_id' => $recipient->getKey(),
                ]);
            }
        }

        if ($sentTo === []) {
            Log::info('ServiceCancelledMail: geen adviseur of monteur met e-mailadres, mail niet verzonden.', [
                'order_id' => $main->getId(),
            ]);

            return;
        }

        $this->logger->logSent($order, ServiceCancelledMail::class, $sentTo);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveCancelledAppointmentRecipients(Main $main, ?Appointment $cancelledAppointment = null): Collection
    {
        return $this->resolveAppointmentAdvisors($main, $cancelledAppointment)
            ->merge($this->resolveAppointmentMechanics($main, $cancelledAppointment))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveAppointmentAdvisors(Main $main, ?Appointment $cancelledAppointment = null): Collection
    {
        if ($cancelledAppointment !== null) {
            $cancelledAppointment->loadMissing('advisors');

            return $cancelledAppointment->advisors
                ->filter(fn (User $advisor): bool => filled($advisor->getEmail()))
                ->unique('id')
                ->values();
        }

        $appointment = Appointment::query()
            ->where('order_id', $main->getId())
            ->where('type', AppointmentType::Service)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->with('advisors')
            ->latest('datetime')
            ->first();

        $advisors = $appointment?->advisors ?? collect();

        return $advisors
            ->filter(fn (User $advisor): bool => filled($advisor->getEmail()))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveAppointmentMechanics(Main $main, ?Appointment $cancelledAppointment = null): Collection
    {
        if ($cancelledAppointment !== null) {
            $cancelledAppointment->loadMissing('mechanics');

            return $cancelledAppointment->mechanics
                ->filter(fn (User $mechanic): bool => filled($mechanic->getEmail()))
                ->unique('id')
                ->values();
        }

        $appointment = Appointment::query()
            ->where('order_id', $main->getId())
            ->where('type', AppointmentType::Service)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->with('mechanics')
            ->latest('datetime')
            ->first();

        $mechanics = $appointment?->mechanics ?? collect();

        return $mechanics
            ->filter(fn (User $mechanic): bool => filled($mechanic->getEmail()))
            ->unique('id')
            ->values();
    }
}

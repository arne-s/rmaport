<?php

namespace App\Http\Controllers\Quote;

use App\Enums\AppointmentType;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Order\Main;
use App\Services\AppointmentCalendarIcsGenerator;
use Symfony\Component\HttpFoundation\Response;

class MainAppointmentIcsController extends Controller
{
    public function fitting(string $main, AppointmentCalendarIcsGenerator $ics): Response
    {
        $order = Main::query()->find($main);
        if ($order === null) {
            abort(404);
        }

        $body = $ics->buildForFitting($order);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, $this->icsResponseHeaders('fitting', $main));
    }

    public function delivery(string $main, AppointmentCalendarIcsGenerator $ics): Response
    {
        $order = Main::query()->find($main);
        if ($order === null) {
            abort(404);
        }

        $body = $ics->buildForDelivery($order);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, $this->icsResponseHeaders('delivery', $main));
    }

    public function fittingCustomer(string $main, AppointmentCalendarIcsGenerator $ics): Response
    {
        $order = Main::query()->find($main);
        if ($order === null) {
            abort(404);
        }

        $appointment = $this->latestActiveAppointment($order, AppointmentType::Fitting);
        if ($appointment === null) {
            abort(404);
        }

        $body = $ics->buildForFittingCustomer($order, $appointment);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, $this->icsResponseHeaders('fitting-customer', $main));
    }

    public function deliveryCustomer(string $main, AppointmentCalendarIcsGenerator $ics): Response
    {
        $order = Main::query()->find($main);
        if ($order === null) {
            abort(404);
        }

        $appointment = $this->latestActiveAppointment($order, AppointmentType::Delivery);
        if ($appointment === null) {
            abort(404);
        }

        $body = $ics->buildForDeliveryCustomer($order, $appointment);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, $this->icsResponseHeaders('delivery-customer', $main));
    }

    public function serviceCustomer(string $main, AppointmentCalendarIcsGenerator $ics): Response
    {
        $order = Main::query()->find($main);
        if ($order === null) {
            abort(404);
        }

        $appointment = $this->latestActiveAppointment($order, AppointmentType::Service);
        if ($appointment === null) {
            abort(404);
        }

        $body = $ics->buildForServiceCustomer($order, $appointment);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, $this->icsResponseHeaders('service-customer', $main));
    }

    private function latestActiveAppointment(Main $order, AppointmentType $type): ?Appointment
    {
        return Appointment::query()
            ->where('order_id', $order->getId())
            ->where('type', $type)
            ->where('is_active', true)
            ->latest()
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function icsResponseHeaders(string $kind, string $mainId): array
    {
        return [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$kind.'-'.$mainId.'.ics"',
        ];
    }
}

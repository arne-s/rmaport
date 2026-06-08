<?php

namespace App\Services;

use App\Enums\AppointmentType;
use App\Models\Address;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order\Main;
use Illuminate\Support\Carbon;

final class AppointmentCalendarIcsGenerator
{
    public function buildForFitting(Main $order): ?string
    {
        $appointment = $order->getActiveFittingAppointment();
        $startAt = $appointment?->datetime;
        if ($startAt === null) {
            return null;
        }

        $endAt = $startAt->copy()->addHour();
        $address = $appointment ? $this->resolveAddressFromAppointment($appointment) : null;
        $summary = 'Passingsafspraak '.$order->getUidFormatted();
        $description = $this->resolveAppointmentDescription($order, AppointmentType::Fitting);

        return $this->buildCalendarDocument(
            stableUid: 'fitting-'.$order->getId().'@rdmobility',
            startAt: $startAt,
            endAt: $endAt,
            summary: $summary,
            address: $address,
            description: $description,
            phoneText: $this->resolveCustomerPhoneText($order),
        );
    }

    public function buildForDelivery(Main $order): ?string
    {
        $appointment = $order->getActiveDeliveryAppointment();
        $startAt = $appointment?->datetime;
        if ($startAt === null) {
            return null;
        }

        $endAt = $startAt->copy()->addHour();
        $address = $appointment ? $this->resolveAddressFromAppointment($appointment) : null;
        $summary = 'Leveringsafspraak '.$order->getUidFormatted();
        $description = $this->resolveAppointmentDescription($order, AppointmentType::Delivery);

        return $this->buildCalendarDocument(
            stableUid: 'delivery-'.$order->getId().'@rdmobility',
            startAt: $startAt,
            endAt: $endAt,
            summary: $summary,
            address: $address,
            description: $description,
            phoneText: $this->resolveCustomerPhoneText($order),
        );
    }

    public function buildForFittingCustomer(Main $order, Appointment $appointment): ?string
    {
        $startAt = $appointment->customer_datetime_start;
        if ($startAt === null) {
            return null;
        }

        $endAt = $startAt->copy()->addMinutes($appointment->customer_duration ?? 60);
        $address = $this->resolveAddressFromAppointment($appointment);
        $summary = 'Passingsafspraak '.$order->getUidFormatted();

        return $this->buildCalendarDocument(
            stableUid: 'fitting-customer-'.$order->getId().'@rdmobility',
            startAt: $startAt,
            endAt: $endAt,
            summary: $summary,
            address: $address,
            description: null,
        );
    }

    public function buildForDeliveryCustomer(Main $order, Appointment $appointment): ?string
    {
        $startAt = $appointment->customer_datetime_start;
        if ($startAt === null) {
            return null;
        }

        $endAt = $startAt->copy()->addMinutes($appointment->customer_duration ?? 60);
        $address = $this->resolveAddressFromAppointment($appointment);
        $summary = 'Leveringsafspraak '.$order->getUidFormatted();

        return $this->buildCalendarDocument(
            stableUid: 'delivery-customer-'.$order->getId().'@rdmobility',
            startAt: $startAt,
            endAt: $endAt,
            summary: $summary,
            address: $address,
            description: null,
        );
    }

    public function buildForServiceCustomer(Main $order, Appointment $appointment): ?string
    {
        $startAt = $appointment->customer_datetime_start;
        if ($startAt === null) {
            return null;
        }

        $endAt = $startAt->copy()->addMinutes($appointment->customer_duration ?? 60);
        $address = $this->resolveAddressFromAppointment($appointment);
        $summary = 'Serviceafspraak '.$order->getUidFormatted();

        return $this->buildCalendarDocument(
            stableUid: 'service-customer-'.$order->getId().'@rdmobility',
            startAt: $startAt,
            endAt: $endAt,
            summary: $summary,
            address: $address,
            description: null,
        );
    }

    private function resolveAppointmentDescription(Main $order, AppointmentType $type): ?string
    {
        $description = Appointment::query()
            ->where('order_id', $order->getId())
            ->where('type', $type)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->latest('datetime')
            ->value('description');

        return filled($description) ? $description : null;
    }

    private function resolveAddressFromAppointment(Appointment $appointment): ?Address
    {
        if ($appointment->location_type === 'customer' && $appointment->location_customer_id !== null) {
            $locationCustomer = $appointment->locationCustomer;

            return $locationCustomer?->billingAddress;
        }

        if ($appointment->location_type === 'phone') {
            return null;
        }

        // Custom location: no Address model, handled via location_custom JSON
        return null;
    }

    private function resolveCustomerPhoneText(Main $order): string
    {
        $customer = $order->customer;
        if ($customer) {
            $phone  = $customer->getPhoneNumber();
            $mobile = $customer->getMobilePhoneNumber();
            $parts  = array_filter([$phone, $mobile]);

            return $parts !== [] ? implode(' / ', $parts) : '';
        }

        $billingCustomer = $order->billingCustomer;
        if ($billingCustomer) {
            $phone = $billingCustomer->getPhoneNumber();
            $parts = array_filter([$phone]);

            return $parts !== [] ? implode(' / ', $parts) : '';
        }

        return '';
    }

    private function buildCalendarDocument(
        string $stableUid,
        Carbon $startAt,
        Carbon $endAt,
        string $summary,
        ?Address $address,
        ?string $description = null,
        string $phoneText = '',
    ): string {
        $now = now()->utc()->format('Ymd\THis\Z');
        $dtStart = $startAt->copy()->setTimezone('Europe/Amsterdam')->format('Ymd\THis');
        $dtEnd = $endAt->copy()->setTimezone('Europe/Amsterdam')->format('Ymd\THis');
        $location = $address
            ? trim($address->getStreetTemplate().', '.$address->getCity())
            : '';

        if ($phoneText !== '') {
            $location = $location !== '' ? $location.', '.$phoneText : $phoneText;
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RD Mobility//Afspraken//NL',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:'.$stableUid,
            'DTSTAMP:'.$now,
            'DTSTART;TZID=Europe/Amsterdam:'.$dtStart,
            'DTEND;TZID=Europe/Amsterdam:'.$dtEnd,
            'SUMMARY:'.$this->escapeIcsText($summary),
            'LOCATION:'.$this->escapeIcsText($location),
        ];

        if ($description !== null) {
            $lines[] = 'DESCRIPTION:'.$this->escapeIcsText($description);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function escapeIcsText(string $text): string
    {
        return str_replace(
            ['\\', "\n", ',', ';'],
            ['\\\\', '\\n', '\\,', '\\;'],
            $text,
        );
    }
}

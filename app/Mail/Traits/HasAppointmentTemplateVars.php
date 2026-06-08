<?php

namespace App\Mail\Traits;

use App\Enums\AppointmentType;
use App\Models\Address;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * Shared appointment template-variable logic for Fitting/Delivery (Confirmation + Changed) mails.
 *
 * Requires the using class to have a `public readonly Main $order` property.
 *
 * Common placeholders include [fitting_type] (type passing from Main.additional).
 */
trait HasAppointmentTemplateVars
{
    /**
     * Build the common appointment template variable array.
     *
     * @param  'fitting'|'delivery'|'service'  $calendarKind
     * @return array<string, string>
     */
    protected function buildAppointmentVars(?Carbon $appointmentAt, ?Address $locationAddress, string $calendarKind): array
    {
        $appointmentType = match ($calendarKind) {
            'fitting' => AppointmentType::Fitting,
            'delivery' => AppointmentType::Delivery,
            'service' => AppointmentType::Service,
            default => null,
        };

        $appointmentDescription = $appointmentType !== null
            ? $this->resolveAppointmentDescriptionForType($appointmentType)
            : '';

        return array_merge(
            $this->resolveAdvisorRecipientTemplateVars(),
            [
                'customer_first_name'      => $this->resolveFirstName(),
                'appointment_date'         => $appointmentAt?->translatedFormat('l d F Y') ?? '',
                'appointment_time'         => $appointmentAt?->format('H:i') ?? '',
                'appointment_street'      => $locationAddress?->getStreetTemplate() ?? '',
                'appointment_city'        => $locationAddress?->getCity() ?? '',
                'appointment_description' => $appointmentDescription,
                'order_number'             => (string) ($this->order->getUid() ?? ''),
                'calendar_link'            => $this->buildAppointmentCalendarAbsoluteUrl($calendarKind),
                'fitting_type'             => $this->resolveFittingTypeTemplateVar(),
            ],
            $this->resolveMainOrderTemplateVars(),
        );
    }

    /**
     * @param  'fitting'|'delivery'|'service'|'fitting-customer'|'delivery-customer'|'service-customer'  $calendarKind
     */
    protected function buildAppointmentCalendarAbsoluteUrl(string $calendarKind): string
    {
        $routeName = match ($calendarKind) {
            'fitting'           => 'quote.calendar.fitting',
            'delivery'          => 'quote.calendar.delivery',
            'fitting-customer'  => 'quote.calendar.fitting-customer',
            'delivery-customer' => 'quote.calendar.delivery-customer',
            'service-customer'  => 'quote.calendar.service-customer',
            default             => null,
        };

        if ($routeName === null) {
            return '';
        }

        return route($routeName, ['main' => $this->order->getId()], absolute: true);
    }

    /**
     * Build appointment vars using customer_datetime_start from the latest active appointment.
     *
     * @param  'fitting-customer'|'delivery-customer'|'service-customer'  $calendarKind
     * @param  \App\Enums\AppointmentType  $appointmentType
     * @return array<string, string>
     */
    protected function buildCustomerAppointmentVars(?Address $locationAddress, string $calendarKind, AppointmentType $appointmentType): array
    {
        $appointment = Appointment::query()
            ->where('order_id', $this->order->getId())
            ->where('type', $appointmentType)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->latest('datetime')
            ->first();

        $customerStart = $appointment?->customer_datetime_start;

        return array_merge($this->resolveCustomerRecipientTemplateVars(), [
            'customer_first_name'       => $this->resolveFirstName(),
            'appointment_date'          => $customerStart?->translatedFormat('l d F Y') ?? '',
            'appointment_time'          => $customerStart?->format('H:i') ?? '',
            'appointment_street'        => $locationAddress?->getStreetTemplate() ?? '',
            'appointment_city'          => $locationAddress?->getCity() ?? '',
            'appointment_description'   => trim((string) ($appointment?->description ?? '')),
            'order_number'              => (string) ($this->order->getUid() ?? ''),
            'calendar_link'             => $this->buildAppointmentCalendarAbsoluteUrl($calendarKind),
            'fitting_type'              => $this->resolveFittingTypeTemplateVar(),
        ], $this->resolveMainOrderTemplateVars());
    }

    protected function resolveFittingTypeTemplateVar(): string
    {
        $key = data_get($this->order->getAdditional(), 'fitting_type');

        if (! is_string($key) || $key === '') {
            return '';
        }

        $label = BaseOrder::fittingTypeLabel($key);

        if ($label === 'Overig') {
            return 'Overige voorziening';
        }

        return $label;
    }

    /**
     * @return array{main_number: string, order_link: string}
     */
    protected function resolveMainOrderTemplateVars(): array
    {
        return [
            'main_number' => $this->order->getUidFormatted() ?? (string) ($this->order->getUid() ?? ''),
            'order_link' => route('filament.app.resources.mains.view', [
                'record' => $this->order->getId(),
            ], true),
        ];
    }

    /**
     * Placeholders aligned with {@see HasTemplate::getTemplateRecipientVars()} for advisor-facing
     * appointment mails (actual To is the order advisor, not template To users).
     *
     * @return array<string, string>
     */
    protected function resolveAdvisorRecipientTemplateVars(): array
    {
        $this->order->loadMissing('advisor');

        $advisor = $this->order->advisor;

        if ($advisor === null) {
            return [
                'user_name' => '',
                'user_first_name' => '',
                'user_last_name' => '',
                'user_email' => '',
            ];
        }

        return [
            'user_name' => $advisor->getName() ?? '',
            'user_first_name' => $advisor->getFirstName() ?? $advisor->getName() ?? '',
            'user_last_name' => (string) ($advisor->getLastName() ?? ''),
            'user_email' => (string) ($advisor->getEmail() ?? ''),
        ];
    }

    /**
     * @return array{user_name: string, user_first_name: string, user_last_name: string, user_email: string}
     */
    protected function resolveMechanicRecipientTemplateVars(User $mechanic): array
    {
        return [
            'user_name' => $mechanic->getName() ?? '',
            'user_first_name' => $mechanic->getFirstName() !== null && $mechanic->getFirstName() !== ''
                ? (string) $mechanic->getFirstName()
                : ($mechanic->getName() ?? ''),
            'user_last_name' => (string) ($mechanic->getLastName() ?? ''),
            'user_email' => (string) ($mechanic->getEmail() ?? ''),
        ];
    }

    /**
     * Preview mechanic: first workshop employee on the active service appointment, else any mechanic role.
     */
    protected static function resolveMechanicUserForEmailPreview(?Main $order): User
    {
        if ($order !== null) {
            $appointment = Appointment::query()
                ->where('order_id', $order->getId())
                ->where('type', AppointmentType::Service)
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('segment')->orWhere('segment', 'appointment');
                })
                ->with('mechanics')
                ->latest('datetime')
                ->first();

            $mechanic = $appointment?->mechanics->first();
            if ($mechanic !== null) {
                return $mechanic;
            }
        }

        return User::query()->role('mechanic')->first() ?? new User;
    }

    /**
     * Preview main for service mechanic mails.
     */
    protected static function resolveMainForServiceMechanicEmailPreview(): Main
    {
        return Main::query()
            ->whereHas('appointments', fn ($query) => $query
                ->where('type', AppointmentType::Service->value)
                ->where('is_active', true)
                ->whereHas('mechanics'))
            ->latest()
            ->first() ?? Main::query()->latest()->first() ?? new Main;
    }

    /**
     * Placeholders aligned with {@see HasTemplate::getTemplateRecipientVars()} for customer-facing
     * appointment mails (actual To is order customer / billing customer, not template To users).
     *
     * @return array<string, string>
     */
    protected function resolveCustomerRecipientTemplateVars(): array
    {
        $customer = $this->order->customer ?? $this->order->billingCustomer;

        if ($customer === null) {
            return [
                'user_name' => '',
                'user_first_name' => '',
                'user_last_name' => '',
                'user_email' => '',
            ];
        }

        return [
            'user_name' => $customer->getName() ?? '',
            'user_first_name' => $this->resolveFirstName(),
            'user_last_name' => (string) ($customer->getLastName() ?? ''),
            'user_email' => (string) ($customer->getEmail() ?? ''),
        ];
    }

    private function resolveAppointmentDescriptionForType(AppointmentType $appointmentType): string
    {
        $active = Appointment::query()
            ->where('order_id', $this->order->getId())
            ->where('type', $appointmentType)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('segment')->orWhere('segment', 'appointment');
            })
            ->latest('datetime')
            ->first();

        return trim((string) ($active?->description ?? ''));
    }

    /**
     * Active fitting appointment, or the most recent fitting appointment (e.g. after cancellation).
     */
    protected function resolveLatestFittingAppointment(): ?Appointment
    {
        return $this->order->getActiveFittingAppointment()
            ?? $this->order->getAppointments(AppointmentType::Fitting)
                ->sortByDesc('datetime')
                ->first();
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function buildFittingAppointmentTemplateVars(array $extra = []): array
    {
        $appointment = $this->resolveLatestFittingAppointment();

        $vars = $this->buildAppointmentVars(
            $appointment?->getDatetime(),
            $appointment !== null ? $this->resolveAppointmentLocationAddress($appointment) : null,
            'fitting',
        );

        if ($appointment !== null) {
            $vars['appointment_description'] = trim((string) ($appointment->description ?? ''));
        }

        return array_merge($vars, $extra);
    }

    /**
     * Active delivery appointment, or the most recent delivery appointment (e.g. after cancellation).
     */
    protected function resolveLatestDeliveryAppointment(): ?Appointment
    {
        if (! $this->order instanceof Main) {
            return null;
        }

        return $this->order->getActiveDeliveryAppointment()
            ?? $this->order->getAppointments(AppointmentType::Delivery)
                ->sortByDesc('datetime')
                ->first();
    }

    /**
     * Active service appointment, or the most recent service appointment (e.g. after cancellation).
     */
    protected function resolveLatestServiceAppointment(): ?Appointment
    {
        if (! $this->order instanceof Main) {
            return null;
        }

        return $this->order->getActiveServiceAppointment()
            ?? $this->order->getAppointments(AppointmentType::Service)
                ->sortByDesc('datetime')
                ->first();
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function buildDeliveryAppointmentTemplateVars(array $extra = []): array
    {
        $appointment = $this->resolveLatestDeliveryAppointment();

        $vars = $this->buildAppointmentVars(
            $appointment?->getDatetime(),
            $appointment !== null ? $this->resolveAppointmentLocationAddress($appointment) : null,
            'delivery',
        );

        if ($appointment !== null) {
            $vars['appointment_description'] = trim((string) ($appointment->description ?? ''));
        }

        return array_merge($vars, $extra);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    protected function buildServiceAppointmentTemplateVars(array $extra = []): array
    {
        $appointment = $this->resolveLatestServiceAppointment();

        $vars = $this->buildAppointmentVars(
            $appointment?->getDatetime(),
            $appointment !== null ? $this->resolveAppointmentLocationAddress($appointment) : null,
            'service',
        );

        if ($appointment !== null) {
            $vars['appointment_description'] = trim((string) ($appointment->description ?? ''));
        }

        return array_merge($vars, $extra);
    }

    /**
     * Resolve the Address for the active fitting appointment location.
     */
    protected function resolveFittingLocationAddress(): ?Address
    {
        return $this->resolveAppointmentLocationAddress(
            $this->order->getActiveFittingAppointment()
        );
    }

    /**
     * Resolve the Address for the active delivery appointment location.
     */
    protected function resolveDeliveryLocationAddress(): ?Address
    {
        return $this->resolveAppointmentLocationAddress(
            $this->order->getActiveDeliveryAppointment()
        );
    }

    /**
     * Location of the active service appointment (not the fitting appointment).
     */
    protected function resolveServiceLocationAddress(): ?Address
    {
        if (! $this->order instanceof Main) {
            return null;
        }

        return $this->resolveAppointmentLocationAddress(
            $this->order->getActiveServiceAppointment()
        );
    }

    private function resolveAppointmentLocationAddress(?Appointment $appointment): ?Address
    {
        if ($appointment === null) {
            return null;
        }

        if ($appointment->location_type === 'custom') {
            $custom = is_string($appointment->location_custom)
                ? json_decode($appointment->location_custom, true)
                : null;
            if (is_array($custom) && filled($custom['street'] ?? null)) {
                $address = new Address($custom);
                $address->country_id = $custom['country_id'] ?? null;

                return $address;
            }

            return null;
        }

        if ($appointment->location_type === 'customer' && $appointment->location_customer_id !== null) {
            $customer = Customer::query()->with(['billingAddress'])->find($appointment->location_customer_id);

            return $customer?->billingAddress;
        }

        return null;
    }

    protected function resolveFirstName(): string
    {
        $customer = $this->order->customer;
        if ($customer !== null) {
            return $customer->getFirstName() ?? $customer->getName() ?? '';
        }

        return $this->order->billingCustomer?->getName() ?? '';
    }

    /**
     * @return array{string|null, string}  [email, name]
     */
    protected function resolveAdvisorRecipient(): array
    {
        $advisor = $this->order->advisor;

        return [$advisor?->getEmail(), $advisor?->getName() ?? ''];
    }

    protected function applyAdvisorDealerContactCcToMailable(Mailable $mail): void
    {
        $fittingNote = $this->order->getFittingNote() ?? [];
        $dealerContactEmail = trim((string) ($fittingNote['advisor_dealer_email'] ?? ''));
        $dealerContactName = trim((string) ($fittingNote['advisor_dealer_name'] ?? ''));

        if ($dealerContactEmail === '' || ! filter_var($dealerContactEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $mail->cc($dealerContactEmail, $dealerContactName !== '' ? $dealerContactName : null);
    }
}

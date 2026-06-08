<?php

namespace App\Filament\Resources\OrderResource\Actions;

use App\Enums\AppointmentType;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Filament\Forms\AddressFormSchema;
use App\Filament\Resources\Mains\MainResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Appointment;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\User;
use App\Http\Livewire\AppointmentCalendarPicker;
use App\Http\Livewire\MicrosoftCategoryMappings;
use App\Actions\SendDeliveryChangedCustomerMailAction;
use App\Actions\SendDeliveryChangedMailAction;
use App\Actions\SendDeliveryConfirmationCustomerMailAction;
use App\Actions\SendDeliveryConfirmationMailAction;
use App\Actions\SendFittingChangedCustomerMailAction;
use App\Actions\SendFittingChangedMailAction;
use App\Actions\SendFittingConfirmationCustomerMailAction;
use App\Actions\SendFittingConfirmationMailAction;
use App\Actions\SendServiceChangedAdvisorMailAction;
use App\Actions\SendServiceChangedCustomerMailAction;
use App\Actions\SendServiceChangedMechanicMailAction;
use App\Actions\HoldDeliveryAppointmentAction;
use App\Actions\HoldFittingAppointmentAction;
use App\Actions\HoldServiceAppointmentAction;
use App\Actions\SendServiceConfirmationAdvisorMailAction;
use App\Actions\SendServiceConfirmationCustomerMailAction;
use App\Actions\SendServiceConfirmationMechanicMailAction;
use App\Models\MicrosoftCategoryMapping;
use App\Models\MailSenderProfile;
use App\Models\MicrosoftMailToken;
use App\Models\MicrosoftToken;
use App\Services\MicrosoftCalendarService;
use App\Services\TravelTimeService;
use App\Support\OutlookEventIds;
use App\View\Components\PanelNotification;
use App\Filament\Forms\Components\EmailRecipientSelect;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Livewire as FilamentLivewire;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class NewAppointmentAction
{
    public static function makeFor(ViewOrder $page, AppointmentType $appointmentType = AppointmentType::Fitting): Action
    {
        $record = $page->record;
        if (!$record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        $isFitting = $appointmentType === AppointmentType::Fitting;
        $isService = $appointmentType === AppointmentType::Service;
        $actionName = match ($appointmentType) {
            AppointmentType::Fitting => 'newAppointment',
            AppointmentType::Delivery => 'newDeliveryAppointment',
            AppointmentType::Service => 'newServiceAppointment',
        };
        $modalHeading = match ($appointmentType) {
            AppointmentType::Fitting => 'Nieuwe afspraak: Passing',
            AppointmentType::Delivery => 'Nieuwe afspraak: Levering',
            AppointmentType::Service => 'Nieuwe afspraak: Onderhoud',
        };

        return Action::make($actionName)
            ->label('Nieuwe afspraak')
            ->hidden(fn (): bool => (bool) $record->is_completed)
            ->modalHeading($modalHeading)
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes([
                'class' => $isService
                    ? 'new-appointment-modal new-appointment-modal--service'
                    : 'new-appointment-modal',
            ])
            ->closeModalByEscaping(false)
            ->fillForm(function () use ($page, $record, $appointmentType): array {
                return self::fillFormAndSyncPicker($page, $record, $appointmentType);
            })
            ->schema(self::buildSchema($record, $appointmentType))
            ->action(function (array $data, Action $action) use ($page, $record, $appointmentType, $isFitting, $isService): void {
                if ($isFitting) {
                    self::submitFitting($page, $record, $data, $action);
                } elseif ($isService) {
                    self::submitService($page, $record, $data, $action);
                } else {
                    self::submitDelivery($page, $record, $data, $action);
                }
            })
            ->modalSubmitActionLabel('Inplannen');
    }

    public static function makeViewForFitting(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('viewFittingAppointment')
            ->label('Bekijken')
            ->modalHeading('Afspraak bekijken')
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes(['class' => 'new-appointment-modal'])
            ->closeModalByEscaping(false)
            ->fillForm(function (array $arguments) use ($page, $record): array {
                $appointment = self::resolveFittingAppointmentFromArguments($record, $arguments);

                return self::fillFormAndSyncPicker($page, $record, AppointmentType::Fitting, $appointment);
            })
            ->schema(fn (array $arguments): array => self::buildSchema(
                $record,
                AppointmentType::Fitting,
                pickerAppointment: self::resolveFittingAppointmentFromArguments($record, $arguments),
                readOnly: true,
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten');
    }

    public static function makeEditForFitting(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('editFittingAppointment')
            ->label('Wijzigen')
            ->hidden(fn (): bool => (bool) $record->is_completed || ! $record->canModifyFittingAppointment())
            ->modalHeading(function (array $arguments) use ($record): string {
                return self::isReplanFittingAppointment($record, $arguments)
                    ? 'Opnieuw inplannen'
                    : 'Afspraak wijzigen';
            })
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes(['class' => 'new-appointment-modal'])
            ->closeModalByEscaping(false)
            ->fillForm(function (array $arguments) use ($page, $record): array {
                if (! $record->canModifyFittingAppointment()) {
                    Notification::make()
                        ->title('Afspraak kan niet worden gewijzigd')
                        ->body('Er is al een verzonden offerte voor deze aanvraag.')
                        ->warning()
                        ->send();

                    return [];
                }

                $appointment = self::resolveFittingAppointmentForEdit($record, $arguments);

                if ($appointment === null) {
                    Notification::make()
                        ->title('Geen passing-afspraak gevonden om te bewerken.')
                        ->danger()
                        ->send();

                    return [];
                }

                $fill = self::fillFormAndSyncPicker($page, $record, AppointmentType::Fitting, $appointment);

                $fill['comment'] = null;

                return $fill;
            })
            ->schema(fn (array $arguments): array => self::buildSchema(
                $record,
                AppointmentType::Fitting,
                pickerAppointment: self::resolveFittingAppointmentForEdit($record, $arguments),
            ))
            ->action(function (array $data, Action $action) use ($page, $record): void {
                self::submitFitting($page, $record, $data, $action);
            })
            ->modalSubmitActionLabel('Inplannen');
    }

    public static function makeCancelForFitting(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('cancelFittingAppointment')
            ->label('Annuleren')
            ->hidden(fn (): bool => (bool) $record->is_completed || ! $record->canModifyFittingAppointment())
            ->modalHeading('Afspraak annuleren')
            ->modalDescription('De passing wordt geannuleerd en de aanvraag gaat naar status On Hold: Opnieuw inplannen. De afspraak wordt uit de agenda verwijderd.')
            ->modalWidth('md')
            ->schema([
                Textarea::make('reason')
                    ->label('Reden annulering')
                    ->required()
                    ->validationMessages([
                        'required' => 'Vul een reden annulering in.',
                    ])
                    ->rows(3)
                    ->maxLength(255),
            ])
            ->action(function (array $data, Action $action) use ($page, $record): void {
                if (! self::ensureFittingAppointmentEditable($record, $action)) {
                    return;
                }

                app(HoldFittingAppointmentAction::class)->execute($record, (string) ($data['reason'] ?? ''));

                $page->syncOrderStatusUiFromDatabase();
                $page->record->refresh();

                Notification::make()
                    ->title('Afspraak geannuleerd.')
                    ->body('Status is gewijzigd naar On Hold: Opnieuw inplannen.')
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Afspraak annuleren')
            ->color('danger');
    }

    public static function makeViewForDelivery(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('viewDeliveryAppointment')
            ->label('Bekijken')
            ->modalHeading('Afspraak bekijken')
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes(['class' => 'new-appointment-modal'])
            ->closeModalByEscaping(false)
            ->fillForm(function (array $arguments) use ($page, $record): array {
                $appointment = self::resolveDeliveryAppointmentFromArguments($record, $arguments);

                return self::fillFormAndSyncPicker($page, $record, AppointmentType::Delivery, $appointment);
            })
            ->schema(fn (array $arguments): array => self::buildSchema(
                $record,
                AppointmentType::Delivery,
                pickerAppointment: self::resolveDeliveryAppointmentFromArguments($record, $arguments),
                readOnly: true,
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten');
    }

    public static function makeEditForDelivery(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('editDeliveryAppointment')
            ->label('Wijzigen')
            ->hidden(fn (): bool => (bool) $record->is_completed)
            ->modalHeading(function (array $arguments) use ($record): string {
                return self::isReplanDeliveryAppointment($record, $arguments)
                    ? 'Opnieuw inplannen'
                    : 'Afspraak wijzigen';
            })
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes(['class' => 'new-appointment-modal'])
            ->closeModalByEscaping(false)
            ->fillForm(function (array $arguments) use ($page, $record): array {
                $appointment = self::resolveDeliveryAppointmentForEdit($record, $arguments);

                if ($appointment === null) {
                    Notification::make()
                        ->title('Geen leveringsafspraak gevonden om te bewerken.')
                        ->danger()
                        ->send();

                    return [];
                }

                $fill = self::fillFormAndSyncPicker($page, $record, AppointmentType::Delivery, $appointment);

                $fill['comment'] = null;

                return $fill;
            })
            ->schema(fn (array $arguments): array => self::buildSchema(
                $record,
                AppointmentType::Delivery,
                pickerAppointment: self::resolveDeliveryAppointmentForEdit($record, $arguments),
            ))
            ->action(function (array $data, Action $action) use ($page, $record): void {
                self::submitDelivery($page, $record, $data, $action);
            })
            ->modalSubmitActionLabel('Inplannen');
    }

    public static function makeCancelForDelivery(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('cancelDeliveryAppointment')
            ->label('Annuleren')
            ->hidden(fn (): bool => (bool) $record->is_completed)
            ->modalHeading('Afspraak annuleren')
            ->modalDescription('De leveringsafspraak wordt geannuleerd en de aanvraag gaat naar status On Hold: Opnieuw inplannen. De afspraak wordt uit de agenda verwijderd.')
            ->modalWidth('md')
            ->schema([
                Textarea::make('reason')
                    ->label('Reden annulering')
                    ->required()
                    ->validationMessages([
                        'required' => 'Vul een reden annulering in.',
                    ])
                    ->rows(3)
                    ->maxLength(255),
            ])
            ->action(function (array $data) use ($page, $record): void {
                app(HoldDeliveryAppointmentAction::class)->execute($record, (string) ($data['reason'] ?? ''));

                $page->syncOrderStatusUiFromDatabase();
                $page->record->refresh();

                Notification::make()
                    ->title('Afspraak geannuleerd.')
                    ->body('Status is gewijzigd naar On Hold: Opnieuw inplannen.')
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Afspraak annuleren')
            ->color('danger');
    }

    public static function makeViewForService(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('viewServiceAppointment')
            ->label('Bekijken')
            ->modalHeading('Afspraak bekijken')
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes(['class' => 'new-appointment-modal new-appointment-modal--service'])
            ->closeModalByEscaping(false)
            ->fillForm(function (array $arguments) use ($page, $record): array {
                $appointment = self::resolveServiceAppointmentFromArguments($record, $arguments);

                return self::fillFormAndSyncPicker($page, $record, AppointmentType::Service, $appointment);
            })
            ->schema(fn (array $arguments): array => self::buildSchema(
                $record,
                AppointmentType::Service,
                pickerAppointment: self::resolveServiceAppointmentFromArguments($record, $arguments),
                readOnly: true,
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten');
    }

    public static function makeEditForService(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('editServiceAppointment')
            ->label('Wijzigen')
            ->hidden(fn (): bool => (bool) $record->is_completed)
            ->modalHeading(function (array $arguments) use ($record): string {
                return self::isReplanServiceAppointment($record, $arguments)
                    ? 'Opnieuw inplannen'
                    : 'Afspraak wijzigen';
            })
            ->modalWidth('full')
            ->stickyModalFooter()
            ->stickyModalHeader()
            ->extraModalWindowAttributes(['class' => 'new-appointment-modal new-appointment-modal--service'])
            ->closeModalByEscaping(false)
            ->fillForm(function (array $arguments) use ($page, $record): array {
                $appointment = self::resolveServiceAppointmentForEdit($record, $arguments);

                if ($appointment === null) {
                    Notification::make()
                        ->title('Geen onderhoudsafspraak gevonden om te bewerken.')
                        ->danger()
                        ->send();

                    return [];
                }

                $fill = self::fillFormAndSyncPicker($page, $record, AppointmentType::Service, $appointment);

                $fill['comment'] = null;

                return $fill;
            })
            ->schema(fn (array $arguments): array => self::buildSchema(
                $record,
                AppointmentType::Service,
                pickerAppointment: self::resolveServiceAppointmentForEdit($record, $arguments),
            ))
            ->action(function (array $data, Action $action) use ($page, $record): void {
                self::submitService($page, $record, $data, $action);
            })
            ->modalSubmitActionLabel('Inplannen');
    }

    public static function makeCancelForService(ViewOrder $page): Action
    {
        $record = $page->record;
        if (! $record instanceof Main) {
            throw new \InvalidArgumentException('ViewOrder record must be Main.');
        }

        return Action::make('cancelServiceAppointment')
            ->label('Annuleren')
            ->hidden(fn (): bool => (bool) $record->is_completed)
            ->modalHeading('Afspraak annuleren')
            ->modalDescription('De onderhoudsafspraak wordt geannuleerd en de aanvraag gaat naar status On Hold: Opnieuw inplannen. De afspraak wordt uit de agenda verwijderd.')
            ->modalWidth('md')
            ->schema([
                Textarea::make('reason')
                    ->label('Reden annulering')
                    ->required()
                    ->validationMessages([
                        'required' => 'Vul een reden annulering in.',
                    ])
                    ->rows(3)
                    ->maxLength(255),
            ])
            ->action(function (array $data) use ($page, $record): void {
                app(HoldServiceAppointmentAction::class)->execute($record, (string) ($data['reason'] ?? ''));

                $page->syncOrderStatusUiFromDatabase();
                $page->record->refresh();

                Notification::make()
                    ->title('Afspraak geannuleerd.')
                    ->body('Status is gewijzigd naar On Hold: Opnieuw inplannen.')
                    ->success()
                    ->send();
            })
            ->modalSubmitActionLabel('Afspraak annuleren')
            ->color('danger');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function isReplanFittingAppointment(Main $record, array $arguments): bool
    {
        $appointmentId = (int) ($arguments['appointmentId'] ?? 0);
        $onHoldAppointmentId = $record->getFittingOnHoldAppointmentId();

        return $appointmentId > 0
            && $onHoldAppointmentId !== null
            && $appointmentId === $onHoldAppointmentId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function isReplanDeliveryAppointment(Main $record, array $arguments): bool
    {
        $appointmentId = (int) ($arguments['appointmentId'] ?? 0);
        $onHoldAppointmentId = $record->getDeliveryOnHoldAppointmentId();

        return $appointmentId > 0
            && $onHoldAppointmentId !== null
            && $appointmentId === $onHoldAppointmentId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function isReplanServiceAppointment(Main $record, array $arguments): bool
    {
        $appointmentId = (int) ($arguments['appointmentId'] ?? 0);
        $onHoldAppointmentId = $record->getAssemblyOnHoldAppointmentId();

        return $appointmentId > 0
            && $onHoldAppointmentId !== null
            && $appointmentId === $onHoldAppointmentId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function resolveFittingAppointmentForEdit(Main $record, array $arguments): ?Appointment
    {
        if (isset($arguments['appointmentId'])) {
            $appointmentId = (int) $arguments['appointmentId'];

            if ($appointmentId <= 0) {
                return null;
            }

            return Appointment::query()
                ->where('order_id', $record->getId())
                ->where('type', AppointmentType::Fitting)
                ->whereKey($appointmentId)
                ->with(['mechanics', 'advisors'])
                ->first();
        }

        return $record->getActiveFittingAppointment();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function resolveFittingAppointmentFromArguments(Main $record, array $arguments): Appointment
    {
        $appointmentId = (int) ($arguments['appointmentId'] ?? 0);

        return Appointment::query()
            ->where('order_id', $record->getId())
            ->where('type', AppointmentType::Fitting)
            ->whereKey($appointmentId)
            ->with(['mechanics', 'advisors'])
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function resolveDeliveryAppointmentForEdit(Main $record, array $arguments): ?Appointment
    {
        if (isset($arguments['appointmentId'])) {
            $appointmentId = (int) $arguments['appointmentId'];

            if ($appointmentId <= 0) {
                return null;
            }

            return Appointment::query()
                ->where('order_id', $record->getId())
                ->where('type', AppointmentType::Delivery)
                ->whereKey($appointmentId)
                ->with(['mechanics', 'advisors'])
                ->first();
        }

        return $record->getActiveDeliveryAppointment();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function resolveDeliveryAppointmentFromArguments(Main $record, array $arguments): Appointment
    {
        $appointmentId = (int) ($arguments['appointmentId'] ?? 0);

        return Appointment::query()
            ->where('order_id', $record->getId())
            ->where('type', AppointmentType::Delivery)
            ->whereKey($appointmentId)
            ->with(['mechanics', 'advisors'])
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function resolveServiceAppointmentForEdit(Main $record, array $arguments): ?Appointment
    {
        if (isset($arguments['appointmentId'])) {
            $appointmentId = (int) $arguments['appointmentId'];

            if ($appointmentId <= 0) {
                return null;
            }

            return Appointment::query()
                ->where('order_id', $record->getId())
                ->where('type', AppointmentType::Service)
                ->whereKey($appointmentId)
                ->with(['mechanics', 'advisors'])
                ->first();
        }

        return $record->getActiveServiceAppointment();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function resolveServiceAppointmentFromArguments(Main $record, array $arguments): Appointment
    {
        $appointmentId = (int) ($arguments['appointmentId'] ?? 0);

        return Appointment::query()
            ->where('order_id', $record->getId())
            ->where('type', AppointmentType::Service)
            ->whereKey($appointmentId)
            ->with(['mechanics', 'advisors'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private static function fillFormAndSyncPicker(
        ViewOrder $page,
        Main $record,
        AppointmentType $appointmentType,
        ?Appointment $sourceAppointment = null,
    ): array {
        $page->appointmentPickerDatetime = null;
        $page->appointmentPickerDurationMinutes = null;
        $page->dispatch('appointment-picker-reset');

        $fill = self::fillFormState($record, $appointmentType, $page, $sourceAppointment);

        $calendarAdvisorIds = is_array($fill['calendar_user_ids'] ?? null)
            ? array_values(array_map('intval', $fill['calendar_user_ids']))
            : [];
        $mechanicIds = is_array($fill['mechanic_user_ids'] ?? null)
            ? array_values(array_map('intval', $fill['mechanic_user_ids']))
            : [];

        $page->dispatch('advisors-changed', advisorUserIds: $calendarAdvisorIds);
        $page->dispatch('mechanics-changed', mechanicUserIds: $mechanicIds);
        self::dispatchPickerTimeOverride($page, $fill);

        return $fill;
    }

    /**
     * @param  array<string, mixed>  $fill
     */
    private static function dispatchPickerTimeOverride(ViewOrder $page, array $fill): void
    {
        $customerTime = trim((string) ($fill['customer_time'] ?? ''));

        if ($customerTime === '') {
            return;
        }

        try {
            $start = Carbon::parse($customerTime);
        } catch (\Throwable) {
            return;
        }

        $duration = (int) ($fill['customer_duration'] ?? 60);

        if ($duration < 1) {
            $duration = 60;
        }

        $end = $start->copy()->addMinutes($duration);
        $page->appointmentPickerDatetime = $start->format('Y-m-d H:i');
        $page->appointmentPickerDurationMinutes = $duration;

        $page->dispatch(
            'appointment-picker-time-override',
            selectedDate: $start->toDateString(),
            timeFrom: $start->format('H:i'),
            timeTo: $end->format('H:i'),
        );
    }

    private static function resolveAppointmentFromAddress(): string
    {
        $profile = MailSenderProfile::where('uid', 'appointments')->first();

        if ($profile) {
            return $profile->name;
        }

        return MicrosoftMailToken::defaultEmail() ?? '';
    }

    private static function resolveAppointmentCalendarEmail(AppointmentType $type): string
    {
        $token = self::getAdvisorToken() ?? self::getMechanicToken();

        if ($token !== null && filled($token->calendar_display_name)) {
            return $token->getCalendarDisplayLabel();
        }

        return $token?->microsoft_email ?? '';
    }

    private static function getAdvisorToken(): ?MicrosoftToken
    {
        return MicrosoftToken::resolveForRoleName('advisor');
    }

    private static function getMechanicToken(): ?MicrosoftToken
    {
        return MicrosoftToken::resolveForRoleName('mechanic');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fillFormState(
        Main $record,
        AppointmentType $appointmentType,
        ViewOrder $page,
        ?Appointment $sourceAppointment = null,
    ): array {
        $customer = $record->customer;
        $fittingNote = $record->getFittingNote() ?? [];

        $customerEmail = $record->getCustomerContactEmail();
        $customerDisplay = $record->getCustomerAddressDisplayName();
        if ($customerDisplay !== '' && filled($customerEmail)) {
            $customerDisplay .= ' (' . $customerEmail . ')';
        }

        $lastAppointment = $sourceAppointment;

        if ($lastAppointment !== null && (! $lastAppointment->relationLoaded('mechanics') || ! $lastAppointment->relationLoaded('advisors'))) {
            $lastAppointment->load(['mechanics', 'advisors']);
        }

        if ($lastAppointment === null) {
            $lastAppointment = Appointment::query()
                ->where('order_id', $record->getId())
                ->where('type', $appointmentType)
                ->where(function ($query): void {
                    $query
                        ->whereNull('segment')
                        ->orWhere('segment', 'appointment');
                })
                ->with(['mechanics', 'advisors'])
                ->latest('datetime')
                ->first();
        }

        $pickerStart = $lastAppointment?->getCustomerDatetimeStart() ?? $lastAppointment?->getDatetime();
        $pickerEnd = $lastAppointment?->datetime_end;
        $customerDuration = $lastAppointment?->customer_duration;

        if ($customerDuration === null && $pickerStart !== null && $pickerEnd !== null) {
            $customerDuration = (int) $pickerStart->diffInMinutes($pickerEnd);
        }

        $defaultTitle = self::buildDefaultAppointmentTitle($record, $appointmentType);

        $description = $lastAppointment?->description ?? null;
        if ($appointmentType === AppointmentType::Service) {
            $fromServiceNote = trim($page->serviceNoteGeneralNotes);
            if ($fromServiceNote !== '') {
                $description = $fromServiceNote;
            }
        }

        $fill = [
            'advisor_id'       => $record->getAdvisorId(),
            '_from_address'    => self::resolveAppointmentFromAddress(),
            '_to_address'      => self::resolveAppointmentCalendarEmail($appointmentType),
            '_customer_name'   => $customerDisplay,
            'dealer_name'      => (string)($fittingNote['advisor_dealer_name'] ?? ''),
            'dealer_email'     => (string)($fittingNote['advisor_dealer_email'] ?? ''),
            'extra_cc'         => $fittingNote['extra_cc'] ?? [],
            'extra_bcc'        => $fittingNote['extra_bcc'] ?? [],
            'title'            => $lastAppointment?->title ?? $defaultTitle,
            'description'      => $description,
            'notify_customer'  => $sourceAppointment !== null
                ? (int) $sourceAppointment->notify_customer
                : 0,
            'notify_advisor'   => $sourceAppointment !== null
                ? (int) $sourceAppointment->notify_advisor
                : 0,
            'comment'          => $lastAppointment?->getComment(),
            'customer_time'    => $pickerStart?->format('Y-m-d H:i'),
            'customer_duration'=> $customerDuration,
            ...self::resolveTravelTimesForFill($lastAppointment),
        ];

        $rdCustomer = Customer::getRdMobilityCustomer();

        // Determine initial location from the last appointment or default
        $locationFormValue = self::appointmentToLocationFormValue($lastAppointment, $rdCustomer, $record, $appointmentType);

        if ($appointmentType === AppointmentType::Delivery) {
            if ($locationFormValue === 'phone') {
                $locationFormValue = 'customer-' . $rdCustomer->id;
            }
        }

        $fill['fitting_location_type'] = $locationFormValue;

        if ($locationFormValue === 'custom') {
            if ($lastAppointment?->location_custom !== null) {
                $customData = is_array($lastAppointment->location_custom)
                    ? $lastAppointment->location_custom
                    : (json_decode($lastAppointment->location_custom, true) ?? []);
                $customData['country_id'] ??= Country::NL_ID;
                $customData['additional'] ??= [];
                $fill['custom_address'] = $customData;
            } else {
                $fill['custom_address'] = ['country_id' => Country::NL_ID, 'additional' => []];
            }
        } else {
            $fill['custom_address'] = ['country_id' => Country::NL_ID, 'additional' => []];
        }

        $previousAdvisorIds = $lastAppointment !== null
            ? $lastAppointment->advisors->pluck('id')->values()->all()
            : [];
        $fill['calendar_user_ids'] = $previousAdvisorIds !== []
            ? $previousAdvisorIds
            : ($fill['advisor_id'] !== null ? [(int) $fill['advisor_id']] : []);
        $fill['mechanic_user_ids'] = $lastAppointment !== null
            ? $lastAppointment->mechanics->pluck('id')->values()->all()
            : [];

        $fill['notify_workshop'] = (int) ($lastAppointment?->notify_workshop ?? false);

        return $fill;
    }

    private static function appointmentToLocationFormValue(
        ?Appointment $appointment,
        Customer $rdCustomer,
        Main $record,
        AppointmentType $appointmentType,
    ): string {
        if ($appointment === null) {
            return self::getDefaultLocationFormValue($record, $rdCustomer, $appointmentType);
        }

        $locationType = $appointment->location_type;

        if ($locationType === 'phone') {
            return 'phone';
        }

        if ($locationType === 'custom') {
            return 'custom';
        }

        if ($locationType === 'customer' && $appointment->location_customer_id !== null) {
            return 'customer-' . $appointment->location_customer_id;
        }

        return self::getDefaultLocationFormValue($record, $rdCustomer, $appointmentType);
    }

    private static function getDefaultLocationFormValue(Main $record, Customer $rdCustomer, AppointmentType $appointmentType): string
    {
        if ($appointmentType === AppointmentType::Fitting) {
            return 'customer-' . $rdCustomer->id;
        }

        $record->loadMissing(['shippingCustomer']);

        $shippingCustomer = $record->shippingCustomer;
        if ($shippingCustomer !== null
            && (int) $shippingCustomer->id !== (int) $record->customer_id
            && (int) $shippingCustomer->id !== (int) $rdCustomer->id
        ) {
            return 'customer-' . $shippingCustomer->id;
        }

        return 'customer-' . $rdCustomer->id;
    }

    /**
     * @return array<string, string>
     */
    private static function getLocationTypeOptionsForRecord(Main $record, AppointmentType $appointmentType): array
    {
        $options = [];
        $rdCustomer = Customer::getRdMobilityCustomer();

        // RD Mobility (default internal location)
        $options['customer-' . $rdCustomer->id] = 'RD Mobility';

        // End customer
        if ($record->customer) {
            $customerName = $record->getCustomerAddressDisplayName();
            $options['customer-' . $record->customer->id] = 'Klant (' . $customerName . ')';
        }

        // Leveradres (shipping customer) when different from end customer
        $record->loadMissing(['shippingCustomer']);
        $shippingCustomer = $record->shippingCustomer;
        if ($shippingCustomer !== null
            && (int) $shippingCustomer->id !== (int) $record->customer_id
            && (int) $shippingCustomer->id !== (int) $rdCustomer->id
        ) {
            $options['customer-' . $shippingCustomer->id] = self::formatLeveradresOptionLabel($shippingCustomer);
        }

        // Phone (only for fitting/service)
        if ($appointmentType !== AppointmentType::Delivery) {
            $options['phone'] = 'Telefonisch';
        }

        $options['custom'] = 'Zelf ingeven';

        return $options;
    }

    /**
     * @return array<int, mixed>
     */
    private static function buildSchema(
        Main $record,
        AppointmentType $appointmentType,
        ?Appointment $pickerAppointment = null,
        bool $readOnly = false,
    ): array {
        $pickerAppointment ??= $record->getAppointments($appointmentType)->sortByDesc('datetime')->first();
        $calendarWeekStart = $pickerAppointment?->datetime !== null
            ? $pickerAppointment->datetime->copy()->startOfWeek(Carbon::MONDAY)->toDateString()
            : null;

        return [
            Html::make('<span tabindex="0" aria-hidden="true" style="position:absolute;opacity:0;width:0;height:0;overflow:hidden;"></span>'),

            Grid::make(2)
                ->columnSpanFull()
                ->extraAttributes(['class' => 'appointment-layout-grid'])
                ->schema([

                    Group::make()
                        ->extraAttributes(['class' => 'appointment-left-col custom-form-design'])
                        ->disabled($readOnly)
                        ->schema([
                            TextInput::make('_from_address')
                                ->label('Vanaf')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                            Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px 0">'),

                            Textarea::make('title')
                                ->label('Afspraak-titel')
                                ->rows(2)
                                ->maxLength(255)
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->extraInputAttributes(['style' => 'border-radius:3px;color:#000;background-color:#fff;border:1px solid #adadad;font-size:11px;padding:5px;min-height:45px;height:45px;box-sizing:border-box;resize:vertical'])
                                ->columnSpanFull(),

                            Textarea::make('description')
                                ->label('Omschrijving')
                                ->rows(2)
                                ->autosize()
                                ->extraInputAttributes(['style' => 'border-radius:3px;color:#000;background-color:#fff;border:1px solid #adadad;font-size:11px;padding:5px'])
                                ->columnSpanFull(),

                            Select::make('fitting_location_type')
                                ->label('Locatie')
                                ->options(fn(): array => self::getLocationTypeOptionsForRecord($record, $appointmentType))
                                ->required()
                                ->selectablePlaceholder(false)
                                ->live()
                                ->columnSpanFull(),

                            Group::make()
                                ->visible(fn(Get $get): bool => ($get('fitting_location_type') ?? '') === 'custom')
                                ->extraAttributes(['class' => 'address-form-fields'])
                                ->statePath('custom_address')
                                ->columns(12)
                                ->columnSpanFull()
                                ->schema([
                                    ...AddressFormSchema::fields(),
                                ]),

                            View::make('filament.resources.mains.partials.location-address')
                                ->viewData(fn(Get $get): array => [
                                    'addressText'        => self::getAddressTextForFormValue($get('fitting_location_type'), $record),
                                    'fittingLocationType'=> $get('fitting_location_type'),
                                    'phoneDisplayText'   => self::getPhoneDisplayTextForFormValue($get('fitting_location_type'), $record),
                                ])
                                ->visible(fn(Get $get): bool => ($get('fitting_location_type') ?? '') !== 'custom')
                                ->columnSpanFull()
                                ->extraAttributes(['class' => 'note-created-by-line']),

                            Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px 0">'),

                            Select::make('calendar_user_ids')
                                ->label('Adviseur(s)')
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap mechanic-user-ids-field'])
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->optionsLimit(500)
                                ->allowHtml()
                                ->live()
                                ->afterStateUpdated(function (?array $state, \Livewire\Component $livewire): void {
                                    $ids = is_array($state)
                                        ? array_values(array_map('intval', $state))
                                        : [];
                                    $livewire->dispatch(
                                        'advisors-changed',
                                        advisorUserIds: $ids,
                                    );
                                })
                                ->options(fn (): array => self::calendarUserSelectOptions($appointmentType))
                                ->getOptionLabelsUsing(fn (array $values): array => self::calendarUserSelectLabelsForValues($values, $appointmentType))
                                ->columnSpanFull(),

                            Select::make('notify_advisor')
                                ->label('Bevestiging')
                                ->options([1 => 'Ja', 0 => 'Nee'])
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->selectablePlaceholder(false)
                                ->live()
                                ->visible(fn (Get $get): bool => self::hasSelectedAdvisors($get))
                                ->columnSpanFull(),

                            Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px 0">'),

                            Select::make('mechanic_user_ids')
                                ->label('Werkplaats')
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap mechanic-user-ids-field'])
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->optionsLimit(500)
                                ->allowHtml()
                                ->live()
                                ->afterStateUpdated(function (?array $state, \Livewire\Component $livewire): void {
                                    $ids = is_array($state)
                                        ? array_values(array_map('intval', $state))
                                        : [];
                                    $livewire->dispatch(
                                        'mechanics-changed',
                                        mechanicUserIds: $ids,
                                    );
                                })
                                ->options(fn (): array => self::mechanicUserSelectOptions($appointmentType))
                                ->getOptionLabelsUsing(fn (array $values): array => self::mechanicUserSelectLabelsForValues($values, $appointmentType))
                                ->columnSpanFull(),

                            Select::make('notify_workshop')
                                ->label('Bevestiging')
                                ->options([1 => 'Ja', 0 => 'Nee'])
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->selectablePlaceholder(false)
                                ->visible(fn (Get $get): bool => self::hasSelectedMechanics($get))
                                ->columnSpanFull(),

                            Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px 0">'),

                            Select::make('notify_customer')
                                ->label('Bevestiging klant')
                                ->options([1 => 'Ja', 0 => 'Nee'])
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->selectablePlaceholder(false)
                                ->live()
                                ->columnSpanFull(),

                            TextInput::make('customer_time')
                                ->hidden()
                                ->dehydratedWhenHidden(),

                            TextInput::make('customer_duration')
                                ->hidden()
                                ->dehydratedWhenHidden(),

                            TextInput::make('travel_time_before')
                                ->hidden()
                                ->default('00:00')
                                ->dehydratedWhenHidden(),

                            TextInput::make('travel_time_after')
                                ->hidden()
                                ->default('00:00')
                                ->dehydratedWhenHidden(),

                            TextInput::make('_customer_name')
                                ->label('Klant')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (Get $get): bool => self::isNotifyCustomerEnabled($get))
                                ->columnSpanFull(),

                            TextInput::make('dealer_email')
                                ->label('Adviseur dealer')
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->email()
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $record->getSubtype() === OrderSubtype::Unit
                                    && self::isNotifyCustomerEnabled($get))
                                ->dehydratedWhenHidden()
                                ->columnSpanFull(),

                            EmailRecipientSelect::make('extra_cc')
                                ->label('Overige (CC)')
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->options(fn(): array => self::getExtraRecipientOptions($record))
                                ->visible(fn (Get $get): bool => self::isNotifyCustomerEnabled($get))
                                ->dehydratedWhenHidden()
                                ->columnSpanFull(),

                            EmailRecipientSelect::make('extra_bcc')
                                ->label('Overige (BCC)')
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->options(fn(): array => self::getExtraRecipientOptions($record))
                                ->visible(fn (Get $get): bool => self::isNotifyCustomerEnabled($get))
                                ->dehydratedWhenHidden()
                                ->columnSpanFull(),

                            Html::make('<hr style="border:none;border-top:1px solid #e5e7eb;margin:15px 0">'),

                            TextInput::make('comment')
                                ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap'])
                                ->label('Reden wijziging')
                                ->visible(fn (): bool => $readOnly || $record->getAppointments($appointmentType)->count() > 0)
                                ->required(fn (): bool => ! $readOnly && $record->getAppointments($appointmentType)->count() > 0)
                                ->columnSpanFull(),
                            Html::make(function () use ($appointmentType, $pickerAppointment, $record, $readOnly): string {
                                $lastAppointment = $pickerAppointment;
                                $readOnlyJs = $readOnly ? 'true' : 'false';
                                $prefillSelectedDate = $lastAppointment?->datetime?->format('Y-m-d') ?? '';
                                $prefillTimeFrom = $lastAppointment?->datetime?->format('H:i') ?? '';
                                $prefillTimeTo = $lastAppointment?->datetime_end !== null
                                    ? $lastAppointment->datetime_end->format('H:i')
                                    : ($lastAppointment?->datetime !== null
                                        ? $lastAppointment->datetime->copy()->addHour()->format('H:i')
                                        : '');
                                $travelTimes = self::resolveTravelTimesForFill($lastAppointment);
                                $jsSingleQuoted = static function (string $value): string {
                                    return "'" . addcslashes($value, "\\'") . "'";
                                };
                                $prefillSelectedDateJs = $prefillSelectedDate === '' ? "''" : $jsSingleQuoted($prefillSelectedDate);
                                $prefillTimeFromJs = $jsSingleQuoted($prefillTimeFrom !== '' ? $prefillTimeFrom : '09:00');
                                $prefillTimeToJs = $jsSingleQuoted($prefillTimeTo !== '' ? $prefillTimeTo : '10:00');
                                $prefillTravelBeforeJs = $jsSingleQuoted($travelTimes['travel_time_before']);
                                $prefillTravelAfterJs = $jsSingleQuoted($travelTimes['travel_time_after']);
                                $prefillCustomerTime = '';
                                $prefillCustomerTimeTo = '';
                                $lastAppointmentKey = $lastAppointment?->id ?? 0;
                                $timeInputStyle = 'display:block;width:100%;max-width:90px;padding:6px 8px;font-size:13px;border-radius:6px;border:1px solid #d1d5db;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)';
                                $disabledTimeStyle = 'display:block;width:100%;max-width:90px;padding:6px 8px;font-size:13px;border-radius:6px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;font-weight:500';
                                $disabledDateStyle = 'display:block;width:100%;max-width:130px;padding:6px 8px;font-size:13px;border-radius:6px;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;font-weight:500;pointer-events:none';
                                $dateInputStyle = 'display:block;width:100%;padding:6px 8px;font-size:13px;border-radius:6px;border:1px solid #d1d5db;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)';
                                $travelCalcPresets = self::travelCalcPresetAddresses($record);
                                $presetRdJs = $jsSingleQuoted($travelCalcPresets['rd']);
                                $presetKlantJs = $jsSingleQuoted($travelCalcPresets['klant']);
                                $locationAddressMapAttr = htmlspecialchars(
                                    json_encode(
                                        self::buildTravelCalcLocationAddressMap($record, $appointmentType),
                                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                                    ),
                                    ENT_QUOTES | ENT_SUBSTITUTE,
                                    'UTF-8',
                                );
                                $hasOrsKeyJs = blank(config('services.ors.key')) ? 'false' : 'true';
                                $calcIconSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 8h8"/><path d="M8 12h2.5"/><path d="M11.75 12h2.5"/><path d="M15.5 12h2.5"/><path d="M8 15.5h2.5"/><path d="M11.75 15.5h2.5"/><path d="M15.5 15.5h2.5"/><path d="M8 19h2.5"/><path d="M11.75 19h2.5"/><path d="M15.5 19h2.5"/></svg>';

                                $calcInputStyle = 'display:block;width:100%;padding:6px 8px;font-size:13px;border-radius:6px;border:1px solid #d1d5db;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.05)';

                                return <<<HTML
<div
    wire:ignore
    wire:key="appointment-time-fields-{$lastAppointmentKey}"
    class="appointment-time-fields"
    data-location-address-map="{$locationAddressMapAttr}"
    x-data="{
        readOnly: {$readOnlyJs},
        initialSelectedDate: {$prefillSelectedDateJs},
        initialTimeFrom: {$prefillTimeFromJs},
        initialTimeTo: {$prefillTimeToJs},
        initialTravelTimeBefore: {$prefillTravelBeforeJs},
        initialTravelTimeAfter: {$prefillTravelAfterJs},
        selectedDate: {$prefillSelectedDateJs},
        dateError: false,
        timeFrom: {$prefillTimeFromJs},
        timeTo: {$prefillTimeToJs},
        travelTimeBefore: {$prefillTravelBeforeJs},
        travelTimeAfter: {$prefillTravelAfterJs},
        travelOutStart: '',
        travelBackEnd: '',
        customerTime: '{$prefillCustomerTime}',
        customerTimeTo: '{$prefillCustomerTimeTo}',
        notifyCustomer: false,
        travelCalcOpen: false,
        travelCalcTarget: 'before',
        calcFrom: '',
        calcTo: '',
        calcResult: null,
        calcLoading: false,
        calcError: null,
        calcDebounceTimer: null,
        presetRd: {$presetRdJs},
        presetKlant: {$presetKlantJs},
        locationAddressMap: {},
        hasOrsKey: {$hasOrsKeyJs},
        locationAddressForCalc() {
            const locType = this.getMountedActionData('fitting_location_type', '');
            if (!locType || locType === 'phone') {
                return '';
            }
            if (locType === 'custom') {
                return this.formatCustomAddressForCalc(this.getMountedActionData('custom_address', null));
            }
            return this.locationAddressMap[locType] ?? '';
        },
        formatCustomAddressForCalc(custom) {
            if (!custom || typeof custom !== 'object') {
                return '';
            }
            const addition = custom.house_number_addition
                ? ' ' + String(custom.house_number_addition).trim()
                : '';
            const line1 = [
                String(custom.street ?? '').trim(),
                String(custom.house_number ?? '').trim() + addition,
            ].filter(Boolean).join(' ');
            const line2 = [
                String(custom.postcode ?? '').trim(),
                String(custom.city ?? '').trim(),
            ].filter(Boolean).join(' ');
            return [line1, line2].filter(Boolean).join(', ');
        },
        openTravelCalc(target) {
            if (this.readOnly) return;
            this.travelCalcTarget = target;
            this.travelCalcOpen = true;
            const locationAddr = this.locationAddressForCalc();
            if (target === 'before') {
                this.calcFrom = '';
                this.calcTo = locationAddr;
            } else {
                this.calcFrom = locationAddr;
                this.calcTo = '';
            }
            this.calcResult = null;
            this.calcError = null;
            this.calcLoading = false;
            \$nextTick(() => target === 'before'
                ? this.\$refs.calcFromInput?.focus()
                : this.\$refs.calcToInput?.focus());
        },
        closeTravelCalc() {
            this.travelCalcOpen = false;
            clearTimeout(this.calcDebounceTimer);
            this.calcFrom = '';
            this.calcTo = '';
            this.calcResult = null;
            this.calcError = null;
            this.calcLoading = false;
        },
        setCalcField(field, preset) {
            const addr = preset === 'rd' ? this.presetRd : this.presetKlant;
            if (!addr) return;
            this[field] = addr;
            this.scheduleTravelCalcFetch();
        },
        scheduleTravelCalcFetch() {
            clearTimeout(this.calcDebounceTimer);
            this.calcDebounceTimer = setTimeout(() => this.fetchTravelCalc(), 450);
        },
        formatCalcDistance(km) {
            return String(km).split('.').join(',');
        },
        async fetchTravelCalc() {
            const from = (this.calcFrom || '').trim();
            const to = (this.calcTo || '').trim();
            if (!from || !to) {
                this.calcResult = null;
                this.calcError = null;
                return;
            }
            if (!this.hasOrsKey) {
                this.calcResult = null;
                this.calcError = 'Reistijdberekening niet geconfigureerd';
                return;
            }
            this.calcLoading = true;
            this.calcError = null;
            this.calcResult = null;
            try {
                const result = await \$wire.calculateAppointmentTravelTime(from, to);
                if (result?.success) {
                    this.calcResult = result;
                    this.calcError = null;
                } else {
                    this.calcResult = null;
                    this.calcError = result?.error || 'Reistijd niet beschikbaar';
                }
            } catch (e) {
                this.calcResult = null;
                this.calcError = 'Reistijd niet beschikbaar';
            } finally {
                this.calcLoading = false;
            }
        },
        confirmTravelCalc() {
            if (!this.calcResult?.travel_time) return;
            if (this.travelCalcTarget === 'before') {
                this.travelTimeBefore = this.calcResult.travel_time;
                this.travelOutStart = this.timeFrom
                    ? this.subtractMinutesFromTime(this.timeFrom, this.parseDurationToMinutes(this.travelTimeBefore))
                    : '';
            } else {
                this.travelTimeAfter = this.calcResult.travel_time;
                this.travelBackEnd = this.timeTo
                    ? this.addMinutesToTime(this.timeTo, this.parseDurationToMinutes(this.travelTimeAfter))
                    : '';
            }
            this.onTravelTimeChange();
            this.closeTravelCalc();
        },
        parseDurationToMinutes(timeStr) {
            if (!timeStr || !/^\\d{1,2}:\\d{2}\$/.test(timeStr)) return 0;
            const [h, m] = timeStr.split(':').map(Number);
            return (h * 60) + m;
        },
        addMinutesToTime(timeStr, minutes) {
            if (!timeStr) return '';
            const total = this.parseDurationToMinutes(timeStr) + minutes;
            const wrapped = ((total % (24 * 60)) + (24 * 60)) % (24 * 60);
            const h = Math.floor(wrapped / 60);
            const m = wrapped % 60;
            return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        },
        subtractMinutesFromTime(timeStr, minutes) {
            return this.addMinutesToTime(timeStr, -minutes);
        },
        minutesToTravelTime(minutes) {
            const safe = Math.max(0, Number(minutes) || 0);
            const h = Math.floor(safe / 60);
            const m = safe % 60;
            return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        },
        syncBoundaryTimesFromTravelDurations() {
            this.travelOutStart = this.timeFrom
                ? this.subtractMinutesFromTime(this.timeFrom, this.parseDurationToMinutes(this.travelTimeBefore))
                : '';
            this.travelBackEnd = this.timeTo
                ? this.addMinutesToTime(this.timeTo, this.parseDurationToMinutes(this.travelTimeAfter))
                : '';
        },
        syncTravelDurationsFromBoundaryTimes() {
            const appointmentStartMinutes = this.parseDurationToMinutes(this.timeFrom);
            const appointmentEndMinutes = this.parseDurationToMinutes(this.timeTo);
            const travelOutStartMinutes = this.parseDurationToMinutes(this.travelOutStart);
            const travelBackEndMinutes = this.parseDurationToMinutes(this.travelBackEnd);

            const travelOutMinutes = Math.max(0, appointmentStartMinutes - travelOutStartMinutes);
            const travelBackMinutes = Math.max(0, travelBackEndMinutes - appointmentEndMinutes);

            this.travelTimeBefore = this.minutesToTravelTime(travelOutMinutes);
            this.travelTimeAfter = this.minutesToTravelTime(travelBackMinutes);
        },
        get duration() {
            if (!this.timeFrom || !this.timeTo) return '\u2014';
            const diff = this.parseDurationToMinutes(this.timeTo) - this.parseDurationToMinutes(this.timeFrom);
            if (diff <= 0) return '\u2014';
            return Math.floor(diff / 60) + ':' + String(diff % 60).padStart(2, '0');
        },
        get computedCustomerTime() {
            if (!this.timeFrom) return '';
            return this.addMinutesToTime(this.timeFrom, this.parseDurationToMinutes(this.travelTimeBefore));
        },
        get computedCustomerTimeTo() {
            if (!this.timeTo) return '';
            return this.subtractMinutesFromTime(this.timeTo, this.parseDurationToMinutes(this.travelTimeAfter));
        },
        get computedTravelOutEnd() {
            return this.timeFrom || '';
        },
        get computedTravelBackStart() {
            return this.timeTo || '';
        },
        get customerDuration() {
            if (!this.customerTime || !this.customerTimeTo) return '\u2014';
            const diff = this.parseDurationToMinutes(this.customerTimeTo) - this.parseDurationToMinutes(this.customerTime);
            if (diff <= 0) return '\u2014';
            return Math.floor(diff / 60) + ':' + String(diff % 60).padStart(2, '0');
        },
        get customerDurationMinutes() {
            if (!this.customerTime || !this.customerTimeTo) return 0;
            return Math.max(0, this.parseDurationToMinutes(this.customerTimeTo) - this.parseDurationToMinutes(this.customerTime));
        },
        getActiveMountedActionIndex() {
            const mountedActions = this.\$wire?.mountedActions;
            if (!Array.isArray(mountedActions) || mountedActions.length === 0) {
                return -1;
            }

            return Math.max(0, mountedActions.length - 1);
        },
        hasResolvableMountedAction() {
            const index = this.getActiveMountedActionIndex();
            if (index < 0) {
                return false;
            }

            const mountedActions = this.\$wire?.mountedActions;
            const action = Array.isArray(mountedActions) ? mountedActions[index] : null;

            return Boolean(action && typeof action === 'object' && action.name);
        },
        setMountedActionData(field, value) {
            if (!this.hasResolvableMountedAction()) {
                return;
            }

            const index = this.getActiveMountedActionIndex();
            this.\$wire.set('mountedActions.' + index + '.data.' + field, value);
        },
        getMountedActionData(field, fallback = null) {
            if (!this.hasResolvableMountedAction()) {
                return fallback;
            }

            const index = this.getActiveMountedActionIndex();
            const value = this.\$wire.get('mountedActions.' + index + '.data.' + field);

            return value ?? fallback;
        },
        syncTravelTimesToWire() {
            this.setMountedActionData('travel_time_before', this.travelTimeBefore || '00:00');
            this.setMountedActionData('travel_time_after', this.travelTimeAfter || '00:00');
        },
        setCustomerTime(val) {
            this.customerTime = val;
            this.setMountedActionData('customer_time', val);
            \$nextTick(() => this.setMountedActionData('customer_duration', this.customerDurationMinutes));
        },
        setCustomerTimeTo(val) {
            this.customerTimeTo = val;
            \$nextTick(() => this.setMountedActionData('customer_duration', this.customerDurationMinutes));
        },
        recalculateCustomerTimes() {
            if (!this.notifyCustomer) return;
            const from = this.computedCustomerTime;
            const to = this.computedCustomerTimeTo;
            if (from) this.setCustomerTime(from);
            if (to) this.setCustomerTimeTo(to);
        },
        onTravelTimeChange() {
            this.syncBoundaryTimesFromTravelDurations();
            this.syncTravelTimesToWire();
            this.recalculateCustomerTimes();
        },
        onBoundaryTimeChange() {
            this.syncTravelDurationsFromBoundaryTimes();
            this.syncTravelTimesToWire();
            this.recalculateCustomerTimes();
        },
        onEmployeeTimeChange() {
            this.syncBoundaryTimesFromTravelDurations();
            this.dispatchOverride();
            this.recalculateCustomerTimes();
        },
        init() {
            try {
                const raw = this.\$el?.dataset?.locationAddressMap;
                this.locationAddressMap = raw ? JSON.parse(raw) : {};
            } catch (e) {
                this.locationAddressMap = {};
            }
            const applyDatetime = (detail) => {
                if (!detail || typeof detail !== 'object') {
                    return;
                }

                this.selectedDate = detail.selectedDate ?? null;
                this.timeFrom = detail.timeFrom ?? '09:00';
                this.timeTo = detail.timeTo ?? '10:00';
                this.dateError = false;
                this.syncBoundaryTimesFromTravelDurations();
                this.recalculateCustomerTimes();
            };
            window.__rdmSyncAppointmentPickerDatetime = applyDatetime;
            const onDatetime = (e) => applyDatetime(e.detail);
            const onReset = () => {
                this.selectedDate = this.initialSelectedDate;
                this.timeFrom = this.initialTimeFrom;
                this.timeTo = this.initialTimeTo;
                this.travelTimeBefore = this.getMountedActionData('travel_time_before', this.travelTimeBefore || this.initialTravelTimeBefore) || '00:00';
                this.travelTimeAfter = this.getMountedActionData('travel_time_after', this.travelTimeAfter || this.initialTravelTimeAfter) || '00:00';
                this.dateError = false;
                this.syncBoundaryTimesFromTravelDurations();
                this.syncTravelTimesToWire();
                this.recalculateCustomerTimes();
            };
            const onDateError = () => { this.dateError = true; };
            window.addEventListener('appointment-picker-datetime-updated', onDatetime);
            let livewireDatetimeCleanup = null;
            if (typeof Livewire !== 'undefined' && typeof Livewire.on === 'function') {
                livewireDatetimeCleanup = Livewire.on('appointment-picker-datetime-updated', applyDatetime);
            }
            window.addEventListener('appointment-picker-cleared', onReset);
            window.addEventListener('appointment-picker-reset', onReset);
            window.addEventListener('appointment-datetime-error', onDateError);
            \$nextTick(() => {
                this.syncBoundaryTimesFromTravelDurations();
                this.syncTravelTimesToWire();
                this.recalculateCustomerTimes();
                if (this.selectedDate && this.timeFrom && this.timeTo) {
                    this.dispatchOverride();
                }
            });

            return () => {
                window.removeEventListener('appointment-picker-datetime-updated', onDatetime);
                if (livewireDatetimeCleanup) {
                    livewireDatetimeCleanup();
                }
                window.removeEventListener('appointment-picker-cleared', onReset);
                window.removeEventListener('appointment-picker-reset', onReset);
                window.removeEventListener('appointment-datetime-error', onDateError);
            };
        },
        dispatchOverride() {
            Livewire.dispatch('appointment-picker-time-override', {
                selectedDate: this.selectedDate,
                timeFrom: this.timeFrom,
                timeTo: this.timeTo
            });
        },
    }"
    x-effect="notifyCustomer = Number(getMountedActionData('notify_customer', 0) ?? 0) === 1; if (notifyCustomer) { recalculateCustomerTimes(); }"
    style="display:grid;grid-template-columns:95px 0 96px 96px;column-gap:4px;row-gap:8px;align-items:end;padding:0"
>
    <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;font-size:11px;font-weight:bold;color:#0d0a0a;padding-bottom:1px;white-space:nowrap">
        <span>Reistijd heen</span>
        <button type="button" class="travel-time-calc-btn" title="Reistijd berekenen" aria-label="Reistijd berekenen"
            x-show="!readOnly"
            @click.stop.prevent="openTravelCalc('before')">{$calcIconSvg}</button>
    </div>
    <div>
        <input type="hidden" x-model="travelTimeBefore">
    </div>
    <div>
        <label style="display:block;font-size:11px;font-weight:bold;color:#0d0a0a;margin-bottom:2px">Van</label>
        <input type="time" x-model="travelOutStart" @change="onBoundaryTimeChange()" :disabled="readOnly" style="{$timeInputStyle}">
    </div>
    <div>
        <label style="display:block;font-size:11px;font-weight:bold;color:#0d0a0a;margin-bottom:2px">Tot</label>
        <input type="time" :value="computedTravelOutEnd" readonly disabled style="{$disabledTimeStyle}">
    </div>

    <div style="display:flex;align-items:center;justify-content:flex-end;font-size:11px;font-weight:bold;color:#0d0a0a;padding-bottom:1px">Afspraak</div>
    <div></div>
    <div>
        <input type="time" x-model="timeFrom" @change="onEmployeeTimeChange()" :disabled="readOnly" style="{$timeInputStyle}">
    </div>
    <div>
        <input type="time" x-model="timeTo" @change="onEmployeeTimeChange()" :disabled="readOnly" style="{$timeInputStyle}">
    </div>

    <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;font-size:11px;font-weight:bold;color:#0d0a0a;padding-bottom:1px;white-space:nowrap">
        <span>Reistijd terug</span>
        <button type="button" class="travel-time-calc-btn" title="Reistijd berekenen" aria-label="Reistijd berekenen"
            x-show="!readOnly"
            @click.stop.prevent="openTravelCalc('after')">{$calcIconSvg}</button>
    </div>
    <div>
        <input type="hidden" x-model="travelTimeAfter">
    </div>
    <div>
        <input type="time" :value="computedTravelBackStart" readonly disabled style="{$disabledTimeStyle}">
    </div>
    <div>
        <input type="time" x-model="travelBackEnd" @change="onBoundaryTimeChange()" :disabled="readOnly" style="{$timeInputStyle}">
    </div>
    <template x-teleport="body">
        <div x-show="travelCalcOpen" x-cloak class="travel-time-calc-overlay" style="display:none"
            x-trap.inert.noscroll="travelCalcOpen"
            @keydown.escape.window="travelCalcOpen && closeTravelCalc()"
            @click.self="closeTravelCalc()">
            <div class="travel-time-calc-modal__window fi-modal-window fi-width-md shadow-xl"
                role="dialog" aria-modal="true" aria-labelledby="travel-calc-title" @click.stop>
                <div class="travel-time-calc-modal__header">
                    <h2 id="travel-calc-title" class="travel-time-calc-modal__heading">Reistijd berekenen</h2>
                </div>
                <div class="travel-time-calc-modal__body">
                    <div class="travel-time-calc-field">
                        <label class="travel-time-calc-label">Van</label>
                        <input type="text" x-ref="calcFromInput" x-model="calcFrom"
                            @input="scheduleTravelCalcFetch()" placeholder="Adres of plaats"
                            style="{$calcInputStyle}">
                        <div class="travel-time-calc-presets">
                            <a href="#" x-show="presetRd" x-on:click.prevent="setCalcField('calcFrom', 'rd')">RD</a>
                            <span x-show="presetRd && presetKlant">&nbsp;/&nbsp;</span>
                            <a href="#" x-show="presetKlant" x-on:click.prevent="setCalcField('calcFrom', 'klant')">Klant</a>
                        </div>
                    </div>
                    <div class="travel-time-calc-field">
                        <label class="travel-time-calc-label">Tot</label>
                        <input type="text" x-ref="calcToInput" x-model="calcTo"
                            @input="scheduleTravelCalcFetch()" placeholder="Adres of plaats"
                            style="{$calcInputStyle}">
                        <div class="travel-time-calc-presets">
                            <a href="#" x-show="presetRd" x-on:click.prevent="setCalcField('calcTo', 'rd')">RD</a>
                            <span x-show="presetRd && presetKlant">&nbsp;/&nbsp;</span>
                            <a href="#" x-show="presetKlant" x-on:click.prevent="setCalcField('calcTo', 'klant')">Klant</a>
                        </div>
                    </div>
                    <div class="travel-time-calc-result" x-show="(calcFrom || '').trim() && (calcTo || '').trim()">
                        <span x-show="calcLoading">Bezig met berekenen...</span>
                        <span x-show="!calcLoading && calcResult"
                            x-text="calcResult ? (formatCalcDistance(calcResult.distance_km) + 'km · ' + calcResult.duration_label) : ''"></span>
                        <span x-show="!calcLoading && !calcResult && calcError" x-text="calcError"
                            class="travel-time-calc-result__error"></span>
                    </div>
                </div>
                <div class="travel-time-calc-modal__footer">
                    <button type="button" class="fi-btn fi-size-md fi-ac-btn-action white"
                        @click="closeTravelCalc()">Annuleren</button>
                    <button type="button"
                        class="fi-btn fi-size-md fi-ac-btn-action fi-color fi-color-primary fi-bg-color-400 hover:fi-bg-color-300 dark:fi-bg-color-600 dark:hover:fi-bg-color-700 fi-text-color-950 hover:fi-text-color-800 dark:fi-text-color-0 dark:hover:fi-text-color-0"
                        :disabled="!calcResult?.travel_time" @click="confirmTravelCalc()">Bevestigen</button>
                </div>
            </div>
        </div>
    </template>
</div>
HTML;
                            }),

                        ]),

                    Group::make()
                        ->extraAttributes(['class' => 'appointment-right-col'])
                        ->schema([


                            FilamentLivewire::make(AppointmentCalendarPicker::class, [
                                'advisorId'              => $record->getAdvisorId(),
                                'appointmentTypeValue'   => $appointmentType->value,
                                'weekStart'              => $calendarWeekStart,
                                'readOnly'               => $readOnly,
                            ])
                                ->key('appointment-calendar-picker'),
                        ]),

                ]),
        ];
    }

    /**
     * Parse the form's fitting_location_type value ('customer-{id}', 'phone', 'custom')
     * into appointment fields.
     *
     * @param array<string, mixed> $data
     * @return array{type: string, customer_id: int|null, custom: array<string,mixed>|null}
     */
    private static function parseLocationFormValue(?string $formValue, array $data): array
    {
        if ($formValue === 'phone') {
            return ['type' => 'phone', 'customer_id' => null, 'custom' => null];
        }

        if ($formValue === 'custom') {
            $customAddress = $data['custom_address'] ?? [];
            unset($customAddress['additional']);

            return ['type' => 'custom', 'customer_id' => null, 'custom' => $customAddress ?: null];
        }

        if (is_string($formValue) && str_starts_with($formValue, 'customer-')) {
            $customerId = (int) str_replace('customer-', '', $formValue);

            return ['type' => 'customer', 'customer_id' => $customerId, 'custom' => null];
        }

        // Fallback: RD customer
        $rdCustomer = Customer::getRdMobilityCustomer();

        return ['type' => 'customer', 'customer_id' => $rdCustomer->id, 'custom' => null];
    }

    /**
     * @return array{start: Carbon, durationMinutes: int}|null
     */
    private static function resolvePickerAppointmentOrNotify(ViewOrder $page, Action $action): ?array
    {
        $raw = $page->appointmentPickerDatetime;
        if ($raw === null || trim($raw) === '') {
            $page->dispatch('appointment-datetime-error');
            Notification::make()
                ->title('Kies een datum en tijd in de kalender.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        try {
            $start = Carbon::parse($raw, config('app.timezone'));
        } catch (\Throwable) {
            $page->dispatch('appointment-datetime-error');
            Notification::make()
                ->title('Ongeldige datum of tijd.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        if ($start->lt(now())) {
            $page->dispatch('appointment-datetime-error');
            Notification::make()
                ->title('Datum en tijd mogen niet in het verleden liggen.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        $duration = $page->appointmentPickerDurationMinutes;
        if ($duration === null || $duration < 1) {
            $duration = 60;
        }

        return ['start' => $start, 'durationMinutes' => $duration];
    }

    private static function clearAppointmentPickerUi(ViewOrder $page): void
    {
        $page->appointmentPickerDatetime = null;
        $page->appointmentPickerDurationMinutes = null;
        $page->dispatch('appointment-picker-reset');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function isNotifyCustomerEnabled(Get $get): bool
    {
        return self::isFormYesFlag($get('notify_customer'));
    }

    private static function isNotifyAdvisorEnabled(Get $get): bool
    {
        return self::isFormYesFlag($get('notify_advisor'));
    }

    private static function hasSelectedAdvisors(Get $get): bool
    {
        $ids = $get('calendar_user_ids');

        return is_array($ids) && $ids !== [];
    }

    private static function hasSelectedMechanics(Get $get): bool
    {
        $ids = $get('mechanic_user_ids');

        return is_array($ids) && $ids !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isNotifyCustomerEnabledFromData(array $data): bool
    {
        return self::isFormYesFlag($data['notify_customer'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isNotifyAdvisorEnabledFromData(array $data): bool
    {
        return self::isFormYesFlag($data['notify_advisor'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isNotifyWorkshopEnabledFromData(array $data): bool
    {
        return self::isFormYesFlag($data['notify_workshop'] ?? null, defaultYes: false);
    }

    private static function isFormYesFlag(mixed $value, bool $defaultYes = true): bool
    {
        if ($value === null) {
            return $defaultYes;
        }

        return (int) $value === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int>
     */
    private static function resolveCalendarAdvisorUserIds(array $data): array
    {
        $rawIds = self::parseIntegerIdListFromFormField(
            $data['calendar_user_ids'] ?? $data['calendar_user_id'] ?? $data['advisor_user_ids'] ?? $data['advisor_id'] ?? null,
        );

        if ($rawIds === []) {
            return [];
        }

        return User::query()
            ->advisors()
            ->whereIn('id', $rawIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int>
     */
    private static function resolveMechanicUserIds(array $data): array
    {
        $rawIds = self::parseIntegerIdListFromFormField($data['mechanic_user_ids'] ?? null);

        if ($rawIds === []) {
            return [];
        }

        return User::query()
            ->role('mechanic')
            ->whereIn('id', $rawIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $advisorUserIds
     * @param  array<int>  $mechanicUserIds
     */
    private static function ensureAtLeastOneAssignee(array $advisorUserIds, array $mechanicUserIds, Action $action): bool
    {
        if ($advisorUserIds !== [] || $mechanicUserIds !== []) {
            return true;
        }

        Notification::make()
            ->title('Selecteer minimaal één adviseur of medewerker werkplaats.')
            ->danger()
            ->send();
        $action->halt();

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function ensureValidCategorySelection(array $data, AppointmentType $type, Action $action): bool
    {
        $mechanicUserIds = self::resolveMechanicUserIds($data);

        if ($mechanicUserIds === [] || self::allMechanicsHaveCategoryMapping($mechanicUserIds)) {
            return true;
        }

        Notification::make()
            ->title('Niet alle monteurs hebben een categorie')
            ->body('Koppel elke monteur aan een categorie in de Outlook-instellingen.')
            ->danger()
            ->send();
        $action->halt();

        return false;
    }

    /**
     * @param  mixed  $value
     * @return array<int>
     */
    private static function parseIntegerIdListFromFormField(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            if ($value === null || $value === '') {
                return [];
            }

            return [(int) $value];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    private static function resolveCustomerDatetime(Carbon $appointmentStart, int $durationMinutes, array $data, Action $action): ?Carbon
    {
        if (! self::isNotifyCustomerEnabledFromData($data)) {
            return $appointmentStart->copy();
        }

        $customerTimeRaw = trim((string) ($data['customer_time'] ?? ''));
        $customerDuration = (int) ($data['customer_duration'] ?? 0);

        $travelBeforeMinutes = self::travelTimeToMinutes($data['travel_time_before'] ?? '00:00');
        $travelAfterMinutes = self::travelTimeToMinutes($data['travel_time_after'] ?? '00:00');

        if ($customerDuration < 1) {
            Notification::make()
                ->title('Reistijd is te groot voor de geplande afspraak.')
                ->body('De klantafspraak heeft geen geldige duur meer. Verkort de reistijd of verleng de afspraak.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        if ($travelBeforeMinutes + $travelAfterMinutes + $customerDuration > $durationMinutes) {
            Notification::make()
                ->title('Reistijd is te groot voor de geplande afspraak.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        if (! preg_match('/^\d{2}:\d{2}$/', $customerTimeRaw)) {
            Notification::make()
                ->title('Vul een geldig tijdstip bij de klant in (HH:MM).')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        $customerDatetime = Carbon::createFromFormat(
            'Y-m-d H:i',
            $appointmentStart->format('Y-m-d') . ' ' . $customerTimeRaw,
            config('app.timezone'),
        );

        $appointmentEnd = $appointmentStart->copy()->addMinutes($durationMinutes);

        if ($customerDatetime->lt($appointmentStart)) {
            Notification::make()
                ->title('Het tijdstip bij de klant valt voor de afspraak.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        if ($customerDatetime->copy()->addMinutes($customerDuration)->gt($appointmentEnd)) {
            Notification::make()
                ->title('De klantafspraak valt buiten de geplande afspraaktijd.')
                ->danger()
                ->send();
            $action->halt();

            return null;
        }

        return $customerDatetime;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     travelOutStart: Carbon,
     *     travelOutEnd: Carbon,
     *     appointmentStart: Carbon,
     *     appointmentEnd: Carbon,
     *     travelBackStart: Carbon,
     *     travelBackEnd: Carbon,
     *     travelOutMinutes: int,
     *     travelBackMinutes: int
     * }
     */
    private static function resolveTripletTimes(Carbon $appointmentStart, int $durationMinutes, array $data): array
    {
        $travelOutMinutes = self::travelTimeToMinutes($data['travel_time_before'] ?? '00:00');
        $travelBackMinutes = self::travelTimeToMinutes($data['travel_time_after'] ?? '00:00');

        $appointmentEnd = $appointmentStart->copy()->addMinutes($durationMinutes);
        $travelOutStart = $appointmentStart->copy()->subMinutes($travelOutMinutes);
        $travelBackEnd = $appointmentEnd->copy()->addMinutes($travelBackMinutes);

        return [
            'travelOutStart' => $travelOutStart,
            'travelOutEnd' => $appointmentStart->copy(),
            'appointmentStart' => $appointmentStart->copy(),
            'appointmentEnd' => $appointmentEnd,
            'travelBackStart' => $appointmentEnd->copy(),
            'travelBackEnd' => $travelBackEnd,
            'travelOutMinutes' => $travelOutMinutes,
            'travelBackMinutes' => $travelBackMinutes,
        ];
    }

    private static function ensureAppointmentsEditable(Main $record, Action $action): bool
    {
        if ($record->is_completed) {
            Notification::make()
                ->title('Afspraak kan niet worden gewijzigd')
                ->body('Deze aanvraag is afgerond.')
                ->warning()
                ->send();
            $action->halt();

            return false;
        }

        return true;
    }

    private static function ensureFittingAppointmentEditable(Main $record, Action $action): bool
    {
        if (! self::ensureAppointmentsEditable($record, $action)) {
            return false;
        }

        if (! $record->canModifyFittingAppointment()) {
            Notification::make()
                ->title('Afspraak kan niet worden gewijzigd')
                ->body('Er is al een verzonden offerte voor deze aanvraag.')
                ->warning()
                ->send();
            $action->halt();

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function submitFitting(ViewOrder $page, Main $record, array $data, Action $action): void
    {
        if (! self::ensureFittingAppointmentEditable($record, $action)) {
            return;
        }

        $resolved = self::resolvePickerAppointmentOrNotify($page, $action);
        if ($resolved === null) {
            return;
        }

        $appointmentStart = $resolved['start'];
        $durationMinutes = $resolved['durationMinutes'];

        $advisorUserIds = self::resolveCalendarAdvisorUserIds($data);
        $mechanicUserIds = self::resolveMechanicUserIds($data);

        if (! self::ensureAtLeastOneAssignee($advisorUserIds, $mechanicUserIds, $action)) {
            return;
        }

        if (! self::ensureValidCategorySelection($data, AppointmentType::Fitting, $action)) {
            return;
        }

        if (self::isNotifyAdvisorEnabledFromData($data) && $advisorUserIds === []) {
            Notification::make()
                ->title('Selecteer minimaal één adviseur voor de bevestiging.')
                ->danger()
                ->send();
            $action->halt();

            return;
        }

        if (self::isNotifyWorkshopEnabledFromData($data) && $mechanicUserIds === []) {
            Notification::make()
                ->title('Selecteer minimaal één werkplaatsmedewerker voor de bevestiging.')
                ->danger()
                ->send();
            $action->halt();

            return;
        }

        $hadAppointments = $record->getAppointments(AppointmentType::Fitting)->count() > 0;

        $locationParsed = self::parseLocationFormValue($data['fitting_location_type'] ?? null, $data);

        if ($advisorUserIds !== []) {
            $record->setAdvisorId($advisorUserIds[0]);
        }
        self::mergeDealerContactIntoFittingNote($record, $page, $data);
        $record->save();

        self::deleteOldPassingDeliveryOutlookEvents($record->getId(), AppointmentType::Fitting);

        Appointment::query()
            ->where('order_id', $record->getId())
            ->where('type', AppointmentType::Fitting)
            ->update(['is_active' => false, 'outlook_event_id' => null, 'outlook_event_ids' => null]);

        $notifyCustomer = self::isNotifyCustomerEnabledFromData($data);
        $notifyAdvisor = self::isNotifyAdvisorEnabledFromData($data);
        $notifyWorkshop = self::isNotifyWorkshopEnabledFromData($data);
        $tripletTimes = self::resolveTripletTimes($appointmentStart, $durationMinutes, $data);
        $subject = filled($data['title'] ?? null)
            ? $data['title']
            : self::buildDefaultAppointmentTitle($record, AppointmentType::Fitting);

        $appointment = Appointment::create([
            'type'                    => AppointmentType::Fitting,
            'datetime'                => $tripletTimes['appointmentStart'],
            'datetime_end'            => $tripletTimes['appointmentEnd'],
            'comment'                 => $data['comment'] ?? null,
            'title'                   => $data['title'] ?? null,
            'description'             => $data['description'] ?? null,
            'notify_customer'         => $notifyCustomer,
            'notify_advisor'          => $notifyAdvisor,
            'notify_workshop'         => $notifyWorkshop,
            'customer_datetime_start' => $tripletTimes['appointmentStart'],
            'customer_duration'       => $durationMinutes,
            'travel_time_before'      => self::normalizeTravelTime($data['travel_time_before'] ?? '00:00'),
            'travel_time_after'       => self::normalizeTravelTime($data['travel_time_after'] ?? '00:00'),
            ...self::workshopCategoryAttributesFromFormData($data),
            'order_id'                => $record->getId(),
            'location_type'           => $locationParsed['type'],
            'location_customer_id'    => $locationParsed['customer_id'],
            'location_custom'         => $locationParsed['custom'] ? json_encode($locationParsed['custom']) : null,
        ]);

        $appointment->advisors()->sync($advisorUserIds);
        $appointment->mechanics()->sync($mechanicUserIds);

        $outlookSync = self::mergeOutlookSyncResults(
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Fitting,
                durationMinutes: $tripletTimes['travelOutMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelOutStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Fitting,
                durationMinutes: $durationMinutes,
                subject: $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['appointmentStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Fitting,
                durationMinutes: $tripletTimes['travelBackMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelBackStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Fitting,
                durationMinutes: $tripletTimes['travelOutMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelOutStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Fitting,
                durationMinutes: $durationMinutes,
                subject: $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['appointmentStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Fitting,
                durationMinutes: $tripletTimes['travelBackMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelBackStart'],
            ),
        );

        if (! $hadAppointments || $record->getOrderStatus() === OrderStatus::FittingOnHold) {
            $record->changeOrderStatus(OrderStatus::FittingPlanned);
        }

        $page->syncOrderStatusUiFromDatabase();

        self::logAppointmentEvent(
            $record,
            $appointment,
            $data,
            $hadAppointments ? 'Afspraak gewijzigd' : 'Afspraak toegevoegd',
        );
        self::notifyAdvisor($record, $data, $appointment, 'Passing', 'Passing verplaatst', 'fitting');

        if ($notifyAdvisor) {
            $appointment->loadMissing('advisors');
            foreach ($appointment->advisors as $advisor) {
                if ($hadAppointments) {
                    app(SendFittingChangedMailAction::class)->execute($record, $advisor, $data['comment'] ?? null);
                } else {
                    app(SendFittingConfirmationMailAction::class)->execute($record, $advisor);
                }
            }
        }

        if ($notifyWorkshop) {
            $appointment->loadMissing('mechanics');
            $notifiedAdvisorIds = $notifyAdvisor
                ? $appointment->advisors->pluck('id')->flip()->all()
                : [];
            foreach ($appointment->mechanics as $mechanic) {
                if (isset($notifiedAdvisorIds[$mechanic->getKey()])) {
                    continue;
                }

                if ($hadAppointments) {
                    app(SendFittingChangedMailAction::class)->execute($record, $mechanic, $data['comment'] ?? null);
                } else {
                    app(SendFittingConfirmationMailAction::class)->execute($record, $mechanic);
                }
            }
        }

        if ($notifyCustomer) {
            if ($hadAppointments) {
                app(SendFittingChangedCustomerMailAction::class)->execute($record, $data['comment'] ?? null);
            } else {
                app(SendFittingConfirmationCustomerMailAction::class)->execute($record);
            }
        }

        if ($outlookSync['errors'] !== []) {
            Notification::make()
                ->title('Afspraak opgeslagen')
                ->body('De afspraak staat in het systeem, maar Outlook kon niet alle agenda-items aanmaken: ' . implode('; ', $outlookSync['errors']))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Afspraak opgeslagen.')
                ->success()
                ->send();
        }

        self::clearAppointmentPickerUi($page);

        $page->dispatch('$refresh');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function submitDelivery(ViewOrder $page, Main $record, array $data, Action $action): void
    {
        if (! self::ensureAppointmentsEditable($record, $action)) {
            return;
        }

        $resolved = self::resolvePickerAppointmentOrNotify($page, $action);
        if ($resolved === null) {
            return;
        }

        $appointmentStart = $resolved['start'];
        $durationMinutes = $resolved['durationMinutes'];

        $advisorUserIds = self::resolveCalendarAdvisorUserIds($data);
        $mechanicUserIds = self::resolveMechanicUserIds($data);

        if (! self::ensureAtLeastOneAssignee($advisorUserIds, $mechanicUserIds, $action)) {
            return;
        }

        if (! self::ensureValidCategorySelection($data, AppointmentType::Delivery, $action)) {
            return;
        }

        if (self::isNotifyAdvisorEnabledFromData($data) && $advisorUserIds === []) {
            Notification::make()
                ->title('Selecteer minimaal één adviseur voor de bevestiging.')
                ->danger()
                ->send();
            $action->halt();

            return;
        }

        if (self::isNotifyWorkshopEnabledFromData($data) && $mechanicUserIds === []) {
            Notification::make()
                ->title('Selecteer minimaal één werkplaatsmedewerker voor de bevestiging.')
                ->danger()
                ->send();
            $action->halt();

            return;
        }

        $hadAppointments = $record->getAppointments(AppointmentType::Delivery)->count() > 0;

        $locationParsed = self::parseLocationFormValue($data['fitting_location_type'] ?? null, $data);

        if ($advisorUserIds !== []) {
            $record->setAdvisorId($advisorUserIds[0]);
        }
        self::mergeDealerContactIntoFittingNote($record, $page, $data);
        $record->save();

        self::deleteOldPassingDeliveryOutlookEvents($record->getId(), AppointmentType::Delivery);

        Appointment::query()
            ->where('order_id', $record->getId())
            ->where('type', AppointmentType::Delivery)
            ->update(['is_active' => false, 'outlook_event_id' => null, 'outlook_event_ids' => null]);

        $notifyCustomer = self::isNotifyCustomerEnabledFromData($data);
        $notifyAdvisor = self::isNotifyAdvisorEnabledFromData($data);
        $notifyWorkshop = self::isNotifyWorkshopEnabledFromData($data);
        $tripletTimes = self::resolveTripletTimes($appointmentStart, $durationMinutes, $data);
        $subject = filled($data['title'] ?? null)
            ? $data['title']
            : self::buildDefaultAppointmentTitle($record, AppointmentType::Delivery);

        $appointment = Appointment::create([
            'type'                    => AppointmentType::Delivery,
            'datetime'                => $tripletTimes['appointmentStart'],
            'datetime_end'            => $tripletTimes['appointmentEnd'],
            'comment'                 => $data['comment'] ?? null,
            'title'                   => $data['title'] ?? null,
            'description'             => $data['description'] ?? null,
            'notify_customer'         => $notifyCustomer,
            'notify_advisor'          => $notifyAdvisor,
            'notify_workshop'         => $notifyWorkshop,
            'customer_datetime_start' => $tripletTimes['appointmentStart'],
            'customer_duration'       => $durationMinutes,
            'travel_time_before'      => self::normalizeTravelTime($data['travel_time_before'] ?? '00:00'),
            'travel_time_after'       => self::normalizeTravelTime($data['travel_time_after'] ?? '00:00'),
            ...self::workshopCategoryAttributesFromFormData($data),
            'order_id'                => $record->getId(),
            'location_type'           => $locationParsed['type'],
            'location_customer_id'    => $locationParsed['customer_id'],
            'location_custom'         => $locationParsed['custom'] ? json_encode($locationParsed['custom']) : null,
        ]);

        $appointment->advisors()->sync($advisorUserIds);
        $appointment->mechanics()->sync($mechanicUserIds);

        $outlookSync = self::mergeOutlookSyncResults(
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Delivery,
                durationMinutes: $tripletTimes['travelOutMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelOutStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Delivery,
                durationMinutes: $durationMinutes,
                subject: $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                formData: $data,
                startAtOverride: $tripletTimes['appointmentStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Delivery,
                durationMinutes: $tripletTimes['travelBackMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelBackStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Delivery,
                durationMinutes: $tripletTimes['travelOutMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelOutStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Delivery,
                durationMinutes: $durationMinutes,
                subject: $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                formData: $data,
                startAtOverride: $tripletTimes['appointmentStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Delivery,
                durationMinutes: $tripletTimes['travelBackMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelBackStart'],
            ),
        );

        if ($outlookSync['errors'] !== []) {
            Notification::make()
                ->title('Leveringsafspraak opgeslagen')
                ->body('De afspraak staat in het systeem, maar Outlook kon niet alle agenda-items aanmaken: ' . implode('; ', $outlookSync['errors']))
                ->warning()
                ->send();
        }

        if ($record->getOrderStatus() !== OrderStatus::Delivered
            && ($record->getOrderStatus() === OrderStatus::DeliveryOnHold || ! $hadAppointments)) {
            $record->changeOrderStatus(OrderStatus::DeliveryPlanned);
        }

        $page->syncOrderStatusUiFromDatabase();

        if (!$hadAppointments) {
            $page->dispatch('switch-order-tab', tab: 'delivery');
        }

        self::logAppointmentEvent(
            $record,
            $appointment,
            $data,
            $hadAppointments ? 'Leveringsafspraak gewijzigd' : 'Leveringsafspraak toegevoegd',
        );
        self::notifyAdvisor($record, $data, $appointment, 'Aflevering', 'Aflevering verplaatst', 'delivery');

        if ($notifyAdvisor) {
            $appointment->loadMissing('advisors');
            foreach ($appointment->advisors as $advisor) {
                if ($hadAppointments) {
                    app(SendDeliveryChangedMailAction::class)->execute($record, $advisor, $data['comment'] ?? null);
                } else {
                    app(SendDeliveryConfirmationMailAction::class)->execute($record, $advisor);
                }
            }
        }

        if ($notifyWorkshop) {
            $appointment->loadMissing('mechanics');
            $notifiedAdvisorIds = $notifyAdvisor
                ? $appointment->advisors->pluck('id')->flip()->all()
                : [];
            foreach ($appointment->mechanics as $mechanic) {
                if (isset($notifiedAdvisorIds[$mechanic->getKey()])) {
                    continue;
                }

                if ($hadAppointments) {
                    app(SendDeliveryChangedMailAction::class)->execute($record, $mechanic, $data['comment'] ?? null);
                } else {
                    app(SendDeliveryConfirmationMailAction::class)->execute($record, $mechanic);
                }
            }
        }

        if ($notifyCustomer) {
            if ($hadAppointments) {
                app(SendDeliveryChangedCustomerMailAction::class)->execute($record, $data['comment'] ?? null);
            } else {
                app(SendDeliveryConfirmationCustomerMailAction::class)->execute($record);
            }
        }

        Notification::make()
            ->title('Leveringsafspraak opgeslagen.')
            ->success()
            ->send();

        self::clearAppointmentPickerUi($page);

        $page->dispatch('$refresh');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function submitService(ViewOrder $page, Main $record, array $data, Action $action): void
    {
        if (! self::ensureAppointmentsEditable($record, $action)) {
            return;
        }

        $resolved = self::resolvePickerAppointmentOrNotify($page, $action);
        if ($resolved === null) {
            return;
        }

        $appointmentStart = $resolved['start'];
        $durationMinutes = $resolved['durationMinutes'];

        $notifyWorkshop = ((int) ($data['notify_workshop'] ?? 0)) === 1;
        $advisorUserIds = self::resolveCalendarAdvisorUserIds($data);
        $mechanicUserIds = self::resolveMechanicUserIds($data);

        if (! self::ensureAtLeastOneAssignee($advisorUserIds, $mechanicUserIds, $action)) {
            return;
        }

        if (! self::ensureValidCategorySelection($data, AppointmentType::Service, $action)) {
            return;
        }

        if (self::isNotifyAdvisorEnabledFromData($data) && $advisorUserIds === []) {
            Notification::make()
                ->title('Selecteer minimaal één adviseur voor de bevestiging.')
                ->danger()
                ->send();
            $action->halt();

            return;
        }

        if ($advisorUserIds !== []) {
            $record->setAdvisorId($advisorUserIds[0]);
        }
        self::mergeDealerContactIntoFittingNote($record, $page, $data);

        $locationParsed = self::parseLocationFormValue($data['fitting_location_type'] ?? null, $data);

        $hadAppointments = $record->getAppointments(AppointmentType::Service)->count() > 0;

        self::deleteOldServiceOutlookEvents($record->getId());

        Appointment::query()
            ->where('order_id', $record->getId())
            ->where('type', AppointmentType::Service)
            ->update(['is_active' => false, 'outlook_event_id' => null, 'outlook_event_ids' => null]);

        $record->save();

        $notifyCustomer = self::isNotifyCustomerEnabledFromData($data);
        $notifyAdvisor = self::isNotifyAdvisorEnabledFromData($data);
        $tripletTimes = self::resolveTripletTimes($appointmentStart, $durationMinutes, $data);
        $subject = filled($data['title'] ?? null)
            ? $data['title']
            : self::buildDefaultAppointmentTitle($record, AppointmentType::Service);

        $appointment = Appointment::create([
            'type'                    => AppointmentType::Service,
            'datetime'                => $tripletTimes['appointmentStart'],
            'datetime_end'            => $tripletTimes['appointmentEnd'],
            'comment'                 => $data['comment'] ?? null,
            'title'                   => $data['title'] ?? null,
            'description'             => $data['description'] ?? null,
            'notify_customer'         => $notifyCustomer,
            'notify_advisor'          => $notifyAdvisor,
            'notify_workshop'         => $notifyWorkshop,
            'customer_datetime_start' => $tripletTimes['appointmentStart'],
            'customer_duration'       => $durationMinutes,
            'travel_time_before'      => self::normalizeTravelTime($data['travel_time_before'] ?? '00:00'),
            'travel_time_after'       => self::normalizeTravelTime($data['travel_time_after'] ?? '00:00'),
            ...self::workshopCategoryAttributesFromFormData($data),
            'order_id'                => $record->getId(),
            'location_type'           => $locationParsed['type'],
            'location_customer_id'    => $locationParsed['customer_id'],
            'location_custom'         => $locationParsed['custom'] ? json_encode($locationParsed['custom']) : null,
        ]);

        $appointment->mechanics()->sync($mechanicUserIds);
        $appointment->advisors()->sync($advisorUserIds);

        $outlookSync = self::mergeOutlookSyncResults(
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Service,
                durationMinutes: $tripletTimes['travelOutMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelOutStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Service,
                durationMinutes: $durationMinutes,
                subject: $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['appointmentStart'],
            ),
            self::syncOutlookEventsForMechanics(
                appointment: $appointment,
                mechanicUserIds: $mechanicUserIds,
                appointmentType: AppointmentType::Service,
                durationMinutes: $tripletTimes['travelBackMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelBackStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Service,
                durationMinutes: $tripletTimes['travelOutMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelOutStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Service,
                durationMinutes: $durationMinutes,
                subject: $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['appointmentStart'],
            ),
            self::syncOutlookEventsForAdvisors(
                appointment: $appointment,
                advisorUserIds: $advisorUserIds,
                appointmentType: AppointmentType::Service,
                durationMinutes: $tripletTimes['travelBackMinutes'],
                subject: 'Reistijd - ' . $subject,
                locationText: self::getOutlookLocationText($appointment),
                relocationReason: is_string($data['comment'] ?? null) ? ($data['comment'] ?? null) : null,
                extraBodyText: $locationParsed['type'] === 'phone' ? self::getPhoneBodyText($record) : null,
                formData: $data,
                startAtOverride: $tripletTimes['travelBackStart'],
            ),
        );

        $record->refresh();
        if ($record->getSubtype() === OrderSubtype::Service
            && in_array($record->getOrderStatus(), [OrderStatus::ReadyForAssembly, OrderStatus::AssemblyOnHold], true)) {
            $record->changeOrderStatus(OrderStatus::AssemblyPlanned);
        }

        $page->syncOrderStatusUiFromDatabase();

        self::logAppointmentEvent(
            $record,
            $appointment,
            $data,
            $hadAppointments ? 'Onderhoudsafspraak gewijzigd' : 'Onderhoudsafspraak toegevoegd',
        );

        if ($notifyCustomer) {
            if ($hadAppointments) {
                app(SendServiceChangedCustomerMailAction::class)->execute($record, $data['comment'] ?? null);
            } else {
                app(SendServiceConfirmationCustomerMailAction::class)->execute($record);
            }
        }

        if ($notifyAdvisor) {
            $appointment->loadMissing('advisors');
            foreach ($appointment->advisors as $advisor) {
                if ($hadAppointments) {
                    app(SendServiceChangedAdvisorMailAction::class)->execute($record, $advisor, $data['comment'] ?? null);
                } else {
                    app(SendServiceConfirmationAdvisorMailAction::class)->execute($record, $advisor);
                }
            }
        }

        if ($appointment->notify_workshop) {
            $appointment->loadMissing('mechanics');
            foreach ($appointment->mechanics as $mechanic) {
                if ($hadAppointments) {
                    app(SendServiceChangedMechanicMailAction::class)->execute($record, $mechanic, $data['comment'] ?? null);
                } else {
                    app(SendServiceConfirmationMechanicMailAction::class)->execute($record, $mechanic);
                }
            }
        }

        if ($outlookSync['errors'] !== []) {
            Notification::make()
                ->title('Afspraak opgeslagen')
                ->body('De afspraak staat in het systeem, maar Outlook kon niet alle agenda-items aanmaken: ' . implode('; ', $outlookSync['errors']))
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Onderhoudsafspraak opgeslagen.')
                ->success()
                ->send();
        }

        self::clearAppointmentPickerUi($page);

        $page->dispatch('$refresh');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function logAppointmentEvent(Main $record, Appointment $appointment, array $data, string $prefix): void
    {
        $dt = $appointment->getDatetime();
        $eventLabel = $prefix . ' – ' . $dt->translatedFormat('d-m-Y H:i');
        if (!empty($data['comment'] ?? null)) {
            $eventLabel .= ' – Reden: ' . $data['comment'];
        }

        $record->orderEvents()->create([
            'type'    => $eventLabel,
            'data'    => [
                'appointment_id' => $appointment->id,
                'datetime'       => $dt->toIso8601String(),
                'comment'        => $data['comment'] ?? null,
            ],
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function notifyAdvisor(
        Main        $record,
        array       $data,
        Appointment $appointment,
        string      $labelNew,
        string      $labelMoved,
        string      $tab,
    ): void {
        $dt = $appointment->getDatetime();
        $uid = $record->getUidFormatted() ?: (string)$record->getId();

        PanelNotification::make()
            ->title('Nieuwe afspraak: ' . (empty($data['comment'] ?? null) ? $labelNew : $labelMoved))
            ->icon('heroicon-s-calendar')
            ->body(
                'Aanvraag: #' . $uid . '<br>Datum/Tijdstip: ' . $dt->translatedFormat('d-m-Y H:i') . '<br>'
                . (!empty($data['comment'] ?? null) ? 'Reden wijziging: ' . $data['comment'] : '')
            )
            ->actions([
                Action::make('click')
                    ->alpineClickHandler("window.location.href='" . route('filament.app.resources.mains.view', [
                            'record' => $record->getId(),
                            'tab' => $tab,
                        ]) . "'"),
            ])
            ->sendToDatabase(auth()->user());
    }

    private static function syncOutlookEvent(
        Appointment               $appointment,
        ?int                      $durationMinutes,
        string                    $subject,
        ?string                   $oldEventId,
        ?string                   $locationText,
        ?string                   $relocationReason,
        ?MicrosoftCategoryMapping $outlookCategoryMapping,
        ?string                   $extraBodyText = null,
    ): array {
        $token = self::getAdvisorToken() ?? self::getMechanicToken();
        if (!$token) {
            return ['id' => null, 'error' => null];
        }

        $service = app(MicrosoftCalendarService::class);

        if ($oldEventId) {
            $service->deleteEvent($token->id, $oldEventId);
        }

        $startAt = $appointment->getDatetime();
        if ($durationMinutes === null) {
            $durationMinutes = 60;
        }

        if ($durationMinutes <= 0) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $endAt = $startAt->copy()->addMinutes($durationMinutes);

        return $service->createEvent(
            tokenId: $token->id,
            subject: $subject,
            startAt: $startAt,
            endAt: $endAt,
            categories: self::outlookEventCategories($service, $token, $outlookCategoryMapping),
            locationDisplay: ($locationText !== null && $locationText !== '') ? $locationText : null,
            relocationReason: $relocationReason,
            body: $appointment->description ?? '',
            extraBodyText: $extraBodyText,
        );
    }

    /**
     * @param  array{errors: list<string>, createdCount: int}  ...$results
     * @return array{errors: list<string>, createdCount: int}
     */
    private static function mergeOutlookSyncResults(array ...$results): array
    {
        $errors = [];
        $createdCount = 0;

        foreach ($results as $result) {
            $errors = [...$errors, ...$result['errors']];
            $createdCount += $result['createdCount'];
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'createdCount' => $createdCount,
        ];
    }

    /**
     * @param array<int> $mechanicUserIds
     * @return array{errors: list<string>, createdCount: int}
     */
    private static function syncOutlookEventsForMechanics(
        Appointment $appointment,
        array       $mechanicUserIds,
        AppointmentType $appointmentType,
        ?int        $durationMinutes,
        string      $subject,
        ?string     $locationText,
        ?string     $relocationReason,
        ?string     $extraBodyText = null,
        array       $formData = [],
        ?Carbon     $startAtOverride = null,
    ): array {
        if ($mechanicUserIds === []) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $token = self::getMechanicToken();
        if ($token === null) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $service = app(MicrosoftCalendarService::class);
        $startAt = $startAtOverride?->copy() ?? $appointment->getDatetime();
        if ($durationMinutes === null) {
            $durationMinutes = 60;
        }

        if ($durationMinutes <= 0) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $endAt = $startAt->copy()->addMinutes($durationMinutes);
        $errors = [];
        $createdCount = 0;

        foreach (self::groupUserIdsByOutlookCategorySignature($service, $token, $mechanicUserIds, $formData) as $groupUserIds) {
            $representativeUserId = $groupUserIds[0];
            $mapping = self::resolveOutlookCategoryMapping($token, $representativeUserId, $formData);

            $result = $service->createEvent(
                tokenId: $token->id,
                subject: $subject,
                startAt: $startAt,
                endAt: $endAt,
                categories: self::outlookEventCategories($service, $token, $mapping),
                locationDisplay: ($locationText !== null && $locationText !== '') ? $locationText : null,
                relocationReason: $relocationReason,
                body: $appointment->description ?? '',
                extraBodyText: $extraBodyText,
            );

            if ($result['id'] !== null) {
                foreach ($groupUserIds as $mechanicUserId) {
                    self::recordMechanicOutlookEventId($appointment, $mechanicUserId, $result['id']);
                }
                $createdCount++;
            } elseif ($result['error'] !== null) {
                $errors[] = $result['error'];
            }
        }

        return ['errors' => $errors, 'createdCount' => $createdCount];
    }

    /**
     * @param  array<int>  $advisorUserIds
     * @param  array<string, mixed>  $formData
     * @return array{errors: list<string>, createdCount: int}
     */
    private static function syncOutlookEventsForAdvisors(
        Appointment $appointment,
        array $advisorUserIds,
        AppointmentType $appointmentType,
        ?int $durationMinutes,
        string $subject,
        ?string $locationText,
        ?string $relocationReason,
        array $formData = [],
        ?string $extraBodyText = null,
        ?Carbon $startAtOverride = null,
    ): array {
        if ($advisorUserIds === []) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $token = self::getAdvisorToken();
        if ($token === null) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $service = app(MicrosoftCalendarService::class);
        $startAt = $startAtOverride?->copy() ?? $appointment->getDatetime();
        if ($durationMinutes === null) {
            $durationMinutes = 60;
        }

        if ($durationMinutes <= 0) {
            return ['errors' => [], 'createdCount' => 0];
        }

        $endAt = $startAt->copy()->addMinutes($durationMinutes);
        $errors = [];
        $createdCount = 0;

        foreach (self::groupUserIdsByOutlookCategorySignature($service, $token, $advisorUserIds, $formData) as $groupUserIds) {
            $representativeUserId = $groupUserIds[0];
            $mapping = self::resolveOutlookCategoryMapping($token, $representativeUserId, $formData);

            $result = $service->createEvent(
                tokenId: $token->id,
                subject: $subject,
                startAt: $startAt,
                endAt: $endAt,
                categories: self::outlookEventCategories($service, $token, $mapping),
                locationDisplay: ($locationText !== null && $locationText !== '') ? $locationText : null,
                relocationReason: $relocationReason,
                body: $appointment->description ?? '',
                extraBodyText: $extraBodyText,
            );

            if ($result['id'] !== null) {
                foreach ($groupUserIds as $advisorUserId) {
                    self::recordAdvisorOutlookEventId($appointment, $advisorUserId, $result['id']);
                }
                $createdCount++;
            } elseif ($result['error'] !== null) {
                $errors[] = $result['error'];
            }
        }

        return ['errors' => $errors, 'createdCount' => $createdCount];
    }

    private static function recordAdvisorOutlookEventId(Appointment $appointment, int $userId, string $eventId): void
    {
        $pivot = $appointment->advisors()->where('users.id', $userId)->first()?->pivot;
        $ids = OutlookEventIds::append(
            OutlookEventIds::collect($pivot?->outlook_event_ids ?? null, $pivot?->outlook_event_id ?? null),
            $eventId,
        );

        $appointment->advisors()->updateExistingPivot($userId, [
            'outlook_event_id'  => $eventId,
            'outlook_event_ids' => $ids,
        ]);
    }

    private static function recordMechanicOutlookEventId(Appointment $appointment, int $userId, string $eventId): void
    {
        $pivot = $appointment->mechanics()->where('users.id', $userId)->first()?->pivot;
        $ids = OutlookEventIds::append(
            OutlookEventIds::collect($pivot?->outlook_event_ids ?? null, $pivot?->outlook_event_id ?? null),
            $eventId,
        );

        $appointment->mechanics()->updateExistingPivot($userId, [
            'outlook_event_id'  => $eventId,
            'outlook_event_ids' => $ids,
        ]);
    }

    /**
     * Group assignees that share the same Outlook category (or none) so one calendar event is created per group.
     *
     * @param  array<int>  $userIds
     * @param  array<string, mixed>  $formData
     * @return list<list<int>>
     */
    private static function groupUserIdsByOutlookCategorySignature(
        MicrosoftCalendarService $service,
        MicrosoftToken $token,
        array $userIds,
        array $formData,
    ): array {
        $groups = [];

        foreach ($userIds as $userId) {
            $mapping = self::resolveOutlookCategoryMapping($token, $userId, $formData);
            $categories = self::outlookEventCategories($service, $token, $mapping);
            sort($categories);
            $signature = implode("\0", array_map(
                fn (string $category): string => mb_strtolower($category),
                $categories,
            ));

            $groups[$signature][] = $userId;
        }

        return array_values($groups);
    }

    private static function deleteOutlookEventId(?MicrosoftToken $token, ?string $eventId, MicrosoftCalendarService $service): void
    {
        if ($token === null || $eventId === null || $eventId === '') {
            return;
        }

        $service->deleteEvent($token->id, $eventId);
    }

    /**
     * @param  list<string>  $eventIds
     */
    private static function deleteOutlookEventIds(?MicrosoftToken $token, array $eventIds, MicrosoftCalendarService $service): void
    {
        foreach ($eventIds as $eventId) {
            self::deleteOutlookEventId($token, $eventId, $service);
        }
    }

    private static function deleteOldPassingDeliveryOutlookEvents(int $orderId, AppointmentType $type): void
    {
        $advisorToken = self::getAdvisorToken();
        $mechanicToken = self::getMechanicToken();

        if ($advisorToken === null && $mechanicToken === null) {
            return;
        }

        $service = app(MicrosoftCalendarService::class);

        $previousAppointments = Appointment::query()
            ->where('order_id', $orderId)
            ->where('type', $type)
            ->where('is_active', true)
            ->with(['advisors', 'mechanics'])
            ->get();

        foreach ($previousAppointments as $previousAppointment) {
            self::deleteOutlookEventIds(
                $advisorToken,
                OutlookEventIds::collect($previousAppointment->outlook_event_ids, $previousAppointment->outlook_event_id),
                $service,
            );

            foreach ($previousAppointment->advisors as $advisor) {
                self::deleteOutlookEventIds(
                    $advisorToken,
                    OutlookEventIds::collect($advisor->pivot->outlook_event_ids ?? null, $advisor->pivot->outlook_event_id ?? null),
                    $service,
                );
            }

            foreach ($previousAppointment->mechanics as $mechanic) {
                self::deleteOutlookEventIds(
                    $mechanicToken,
                    OutlookEventIds::collect($mechanic->pivot->outlook_event_ids ?? null, $mechanic->pivot->outlook_event_id ?? null),
                    $service,
                );
            }
        }
    }

    private static function deleteOldServiceOutlookEvents(int $orderId): void
    {
        $advisorToken = self::getAdvisorToken();
        $mechanicToken = self::getMechanicToken();

        if ($advisorToken === null && $mechanicToken === null) {
            return;
        }

        $service = app(MicrosoftCalendarService::class);

        $previousAppointments = Appointment::query()
            ->where('order_id', $orderId)
            ->where('type', AppointmentType::Service)
            ->where('is_active', true)
            ->with(['advisors', 'mechanics'])
            ->get();

        foreach ($previousAppointments as $previousAppointment) {
            self::deleteOutlookEventIds(
                $advisorToken,
                OutlookEventIds::collect($previousAppointment->outlook_event_ids, $previousAppointment->outlook_event_id),
                $service,
            );

            foreach ($previousAppointment->mechanics as $mechanic) {
                self::deleteOutlookEventIds(
                    $mechanicToken,
                    OutlookEventIds::collect($mechanic->pivot->outlook_event_ids ?? null, $mechanic->pivot->outlook_event_id ?? null),
                    $service,
                );
            }

            foreach ($previousAppointment->advisors as $advisor) {
                self::deleteOutlookEventIds(
                    $advisorToken,
                    OutlookEventIds::collect($advisor->pivot->outlook_event_ids ?? null, $advisor->pivot->outlook_event_id ?? null),
                    $service,
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function buildTravelCalcLocationAddressMap(Main $record, AppointmentType $appointmentType): array
    {
        $map = [];

        foreach (self::getLocationTypeOptionsForRecord($record, $appointmentType) as $key => $label) {
            if (! is_string($key) || ! str_starts_with($key, 'customer-')) {
                continue;
            }

            $address = self::resolveLocationAddressTemplate($key, $record);
            if ($address !== '') {
                $map[$key] = $address;
            }
        }

        return $map;
    }

    /**
     * @return array{rd: string, klant: string}
     */
    private static function travelCalcPresetAddresses(Main $record): array
    {
        $rdCustomer = Customer::getRdMobilityCustomer();
        $rd = $rdCustomer->billingAddress?->getAddressTemplate() ?? '';

        $klantFormValue = $record->customer !== null
            ? 'customer-' . $record->customer->id
            : null;
        $klant = $klantFormValue !== null
            ? self::resolveLocationAddressTemplate($klantFormValue, $record)
            : '';

        return ['rd' => $rd, 'klant' => $klant];
    }

    /**
     * @return array{success: bool, distance_km?: float, duration_minutes?: int, duration_label?: string, travel_time?: string, error?: string}
     */
    public static function calculateTravelBetweenAddresses(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '') {
            return ['success' => false, 'error' => ''];
        }

        $apiKey = config('services.ors.key');
        if (blank($apiKey)) {
            return ['success' => false, 'error' => 'Reistijdberekening niet geconfigureerd'];
        }

        $service = new TravelTimeService($apiKey);
        $result = $service->calculate($from, $to);

        if ($result === null) {
            return ['success' => false, 'error' => 'Reistijd niet beschikbaar'];
        }

        return [
            'success' => true,
            'distance_km' => $result['distance_km'],
            'duration_minutes' => $result['duration_minutes'],
            'duration_label' => TravelTimeService::formatDuration($result),
            'travel_time' => self::minutesToTravelTimeString($result['duration_minutes']),
        ];
    }

    private static function buildDefaultAppointmentTitle(Main $record, AppointmentType $appointmentType): string
    {
        $main = $record->getMain() ?? $record;

        return match ($appointmentType) {
            AppointmentType::Fitting => self::buildPassingOrDeliveryAppointmentTitle($main, 'Passing'),
            AppointmentType::Delivery => self::buildPassingOrDeliveryAppointmentTitle($main, 'Levering'),
            AppointmentType::Service => self::buildServiceAppointmentTitle($main),
        };
    }

    private static function buildPassingOrDeliveryAppointmentTitle(Main $main, string $prefix): string
    {
        $customerName = $main->getCustomerAddressDisplayName();
        $fittingTypeKey = data_get($main->getAdditional(), 'fitting_type');
        $fittingTypeLabel = is_string($fittingTypeKey) && $fittingTypeKey !== ''
            ? BaseOrder::fittingTypeLabel($fittingTypeKey)
            : '';

        $requestNumber = $main->getUidFormatted() ?? (string) ($main->getUid() ?? '');

        return sprintf(
            '%s: %s | %s | %s',
            $prefix,
            $customerName !== '' ? $customerName : '-',
            $fittingTypeLabel !== '' ? $fittingTypeLabel : '-',
            $requestNumber !== '' ? $requestNumber : '-',
        );
    }

    private static function buildServiceAppointmentTitle(Main $main): string
    {
        $customerName = $main->getCustomerAddressDisplayName();
        $suffix = $customerName !== '' ? ' – ' . $customerName : '';

        return 'Onderhoud aanvraag ' . $main->getUidFormatted() . $suffix;
    }

    private static function getOutlookLocationText(Appointment $appointment): string
    {
        if ($appointment->location_type === 'phone') {
            return 'Telefonisch';
        }

        if ($appointment->location_type === 'custom') {
            $custom = is_array($appointment->location_custom)
                ? $appointment->location_custom
                : (json_decode((string)$appointment->location_custom, true) ?? []);
            $parts = array_filter([
                trim(($custom['street'] ?? '') . ' ' . ($custom['house_number'] ?? '') . ($custom['house_number_addition'] ? ' ' . $custom['house_number_addition'] : '')),
                trim(($custom['postcode'] ?? '') . ' ' . ($custom['city'] ?? '')),
            ]);

            return trim(implode(', ', $parts));
        }

        if ($appointment->location_type === 'customer' && $appointment->location_customer_id !== null) {
            $appointment->loadMissing('order');
            $order = $appointment->order;

            if ($order instanceof Main && self::isOrderDeliveryCustomer($order, (int) $appointment->location_customer_id)) {
                return $order->shippingAddress?->getAddressTemplate() ?? '';
            }

            $customer = Customer::query()->find($appointment->location_customer_id);

            return $customer?->getPhysicalDeliveryAddress()?->getAddressTemplate() ?? '';
        }

        return '';
    }

    private static function getPhoneBodyText(Main $record): ?string
    {
        $customer = $record->customer;
        if ($customer === null) {
            return null;
        }

        $phone = $customer->getPhoneNumber() ?? '-';
        $mobile = $customer->getMobilePhoneNumber() ?? '-';

        $text = 'Telefoonnummer: ' . $phone . ' / Mobiel nummer: ' . $mobile;

        return trim($text) !== '' ? $text : null;
    }

    private static function getAddressTextForFormValue(?string $formValue, ?Main $record = null): string
    {
        if ($record === null) {
            return '';
        }

        return self::resolveLocationAddressTemplate($formValue, $record);
    }

    private static function formatLeveradresOptionLabel(Customer $shippingCustomer): string
    {
        $name = $shippingCustomer->getPhysicalDeliveryAddress()?->getLocationName()
            ?? $shippingCustomer->getName()
            ?? '';

        return 'Leveradres (' . $name . ')';
    }

    private static function isOrderDeliveryCustomer(Main $record, int $customerId): bool
    {
        if ($customerId === (int) $record->shipping_customer_id) {
            return true;
        }

        return $record->shipping_customer_id === null
            && $customerId === (int) $record->customer_id;
    }

    private static function resolveLocationAddressTemplate(?string $formValue, Main $record): string
    {
        if (blank($formValue) || $formValue === 'phone' || $formValue === 'custom') {
            return '';
        }

        if (! is_string($formValue) || ! str_starts_with($formValue, 'customer-')) {
            return '';
        }

        $customerId = (int) str_replace('customer-', '', $formValue);
        $rdCustomer = Customer::getRdMobilityCustomer();

        if ($customerId === (int) $rdCustomer->id) {
            return $rdCustomer->billingAddress?->getAddressTemplate() ?? '';
        }

        if (self::isOrderDeliveryCustomer($record, $customerId)) {
            return $record->shippingAddress?->getAddressTemplate() ?? '';
        }

        $customer = Customer::query()->find($customerId);

        return $customer?->getPhysicalDeliveryAddress()?->getAddressTemplate() ?? '';
    }

    private static function getPhoneDisplayTextForFormValue(?string $formValue, Main $record): string
    {
        if ($formValue !== 'phone') {
            return '';
        }

        $phone = $record->getCustomerContactPhone() ?: '-';
        $mobile = $record->getCustomerContactMobile() ?: '-';

        return 'Telefoonnummer: ' . $phone . ' / ' . 'Mobiel nummer: ' . $mobile;
    }

    private const OUTLOOK_CATEGORY_FALLBACK_COLOR = '#e8e8e8';

    /**
     * @return array{travel_time_before: string, travel_time_after: string}
     */
    private static function resolveTravelTimesForFill(?Appointment $lastAppointment): array
    {
        if ($lastAppointment === null) {
            return [
                'travel_time_before' => '00:00',
                'travel_time_after' => '00:00',
            ];
        }

        $before = $lastAppointment->travel_time_before ?? null;
        $after = $lastAppointment->travel_time_after ?? null;

        if (filled($before) && filled($after)) {
            return [
                'travel_time_before' => self::normalizeTravelTime($before),
                'travel_time_after' => self::normalizeTravelTime($after),
            ];
        }

        return self::deriveTravelTimesFromAppointment($lastAppointment);
    }

    /**
     * @return array{travel_time_before: string, travel_time_after: string}
     */
    private static function deriveTravelTimesFromAppointment(Appointment $appointment): array
    {
        $before = '00:00';
        $after = '00:00';

        if ($appointment->customer_datetime_start !== null && $appointment->datetime !== null) {
            $beforeMinutes = (int) $appointment->datetime->diffInMinutes($appointment->customer_datetime_start, false);

            if ($beforeMinutes > 0) {
                $before = self::minutesToTravelTimeString($beforeMinutes);
            }
        }

        return [
            'travel_time_before' => $before,
            'travel_time_after' => $after,
        ];
    }

    private static function normalizeTravelTime(mixed $value): string
    {
        $raw = trim((string) ($value ?? '00:00'));

        if (! preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
            return '00:00';
        }

        [$hours, $minutes] = array_map('intval', explode(':', $raw));

        return sprintf('%02d:%02d', max(0, min(23, $hours)), max(0, min(59, $minutes)));
    }

    private static function travelTimeToMinutes(mixed $value): int
    {
        $normalized = self::normalizeTravelTime($value);
        [$hours, $minutes] = array_map('intval', explode(':', $normalized));

        return ($hours * 60) + $minutes;
    }

    public static function minutesToTravelTimeString(int $minutes): string
    {
        $minutes = max(0, min((23 * 60) + 59, $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return array{microsoft_category_mapping_id: ?int, workshop_category_by_user: bool}
     */
    private static function workshopCategoryAttributesFromFormData(array $data): array
    {
        if (self::resolveMechanicUserIds($data) === []) {
            return [
                'microsoft_category_mapping_id' => null,
                'workshop_category_by_user' => false,
            ];
        }

        return [
            'microsoft_category_mapping_id' => null,
            'workshop_category_by_user' => true,
        ];
    }

    /**
     * @param  array<int>  $advisorUserIds
     * @param  array<int>  $mechanicUserIds
     */
    private static function allAssigneesHaveCategoryMapping(array $advisorUserIds, array $mechanicUserIds): bool
    {
        $advisorUserIds = array_values(array_unique(array_filter(array_map('intval', $advisorUserIds))));
        $mechanicUserIds = array_values(array_unique(array_filter(array_map('intval', $mechanicUserIds))));

        if ($advisorUserIds === [] && $mechanicUserIds === []) {
            return false;
        }

        $advisorToken = self::getAdvisorToken();
        $mechanicToken = self::getMechanicToken();

        foreach ($advisorUserIds as $userId) {
            if ($advisorToken === null) {
                return false;
            }

            $hasMapping = MicrosoftCategoryMapping::query()
                ->where('microsoft_token_id', $advisorToken->id)
                ->where('user_id', $userId)
                ->exists();

            if (! $hasMapping) {
                return false;
            }
        }

        foreach ($mechanicUserIds as $userId) {
            if ($mechanicToken === null) {
                return false;
            }

            $hasMapping = MicrosoftCategoryMapping::query()
                ->where('microsoft_token_id', $mechanicToken->id)
                ->where('user_id', $userId)
                ->exists();

            if (! $hasMapping) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int>  $mechanicUserIds
     */
    private static function allMechanicsHaveCategoryMapping(array $mechanicUserIds): bool
    {
        return self::allAssigneesHaveCategoryMapping([], $mechanicUserIds);
    }

    private static function categoryMappingHexColor(MicrosoftCategoryMapping $mapping): string
    {
        if (filled($mapping->hex_color)) {
            return (string) $mapping->hex_color;
        }

        return MicrosoftCategoryMappings::PRESET_COLORS[$mapping->category_color ?? 'none']
            ?? self::OUTLOOK_CATEGORY_FALLBACK_COLOR;
    }

    /**
     * @return array<int, string>
     */
    private static function outlookCategoryColorsForRole(string $roleName): array
    {
        $token = MicrosoftToken::resolveForRoleName($roleName);

        if ($token === null) {
            return [];
        }

        $colors = [];

        $mappings = MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get();

        foreach ($mappings as $mapping) {
            $userId = (int) $mapping->user_id;
            if (isset($colors[$userId])) {
                continue;
            }

            $colors[$userId] = self::categoryMappingHexColor($mapping);
        }

        return $colors;
    }

    /**
     * @return array<int, string>
     */
    private static function calendarUserSelectOptions(AppointmentType $type): array
    {
        $colors = self::outlookCategoryColorsForRole('advisor');

        return User::query()
            ->advisors()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => self::mechanicSelectOptionHtml($user, $colors[$user->id] ?? null),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function mechanicUserSelectOptions(AppointmentType $type): array
    {
        $colors = self::outlookCategoryColorsForRole('mechanic');

        return User::query()
            ->role('mechanic')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => self::mechanicSelectOptionHtml($user, $colors[$user->id] ?? null),
            ])
            ->all();
    }

    /**
     * @param  array<int|string>  $values
     * @return array<int|string, string>
     */
    private static function mechanicUserSelectLabelsForValues(array $values, AppointmentType $type): array
    {
        if ($values === []) {
            return [];
        }

        $colors = self::outlookCategoryColorsForRole('mechanic');
        $userIds = array_values(array_map('intval', $values));

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => self::mechanicSelectOptionHtml($user, $colors[$user->id] ?? null),
            ])
            ->all();
    }

    /**
     * @param  array<int|string>  $values
     * @return array<int|string, string>
     */
    private static function calendarUserSelectLabelsForValues(array $values, AppointmentType $type): array
    {
        if ($values === []) {
            return [];
        }

        $colors = self::outlookCategoryColorsForRole('advisor');
        $userIds = array_values(array_map('intval', $values));

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => self::mechanicSelectOptionHtml($user, $colors[$user->id] ?? null),
            ])
            ->all();
    }

    private static function mechanicSelectOptionHtml(User $user, ?string $backgroundColor): string
    {
        $name = htmlspecialchars(trim($user->first_name . ' ' . $user->last_name), ENT_QUOTES, 'UTF-8');
        $background = htmlspecialchars($backgroundColor ?? self::OUTLOOK_CATEGORY_FALLBACK_COLOR, ENT_QUOTES, 'UTF-8');

        return '<span class="mechanic-category-option" style="background-color:'
            . $background
            . '"><span class="mechanic-category-option__name">'
            . $name
            . '</span></span>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * @return array<int, string>
     */
    private static function outlookEventCategories(
        MicrosoftCalendarService $service,
        MicrosoftToken $token,
        ?MicrosoftCategoryMapping $mapping,
    ): array {
        $categories = [];

        if ($mapping !== null) {
            $displayName = $service->resolveMasterCategoryDisplayName($token->id, $mapping->category_name);

            if ($displayName !== null) {
                $categories[] = $displayName;
            }
        }

        $generalCategory = self::resolveTokenGeneralCategory($service, $token);

        if ($generalCategory !== null) {
            $categories[] = $generalCategory;
        }

        return array_values(array_unique($categories));
    }

    private static function resolveTokenGeneralCategory(
        MicrosoftCalendarService $service,
        MicrosoftToken $token,
    ): ?string {
        $generalCategoryName = trim((string) ($token->general_category_name ?? ''));

        if ($generalCategoryName === '') {
            return null;
        }

        return $service->resolveMasterCategoryDisplayName($token->id, $generalCategoryName);
    }

    private static function resolveOutlookCategoryMapping(
        MicrosoftToken $token,
        int            $userId,
        array          $data,
    ): ?MicrosoftCategoryMapping {
        return MicrosoftCategoryMapping::query()
            ->where('microsoft_token_id', $token->id)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private static function getExtraRecipientOptions(Main $record): array
    {
        $options = [];

        $advisor = $record->advisor;
        if ($advisor?->getEmail()) {
            $options[$advisor->getEmail()] = 'Adviseur: ' . $advisor->getName() . ' <' . $advisor->getEmail() . '>';
        }

        $customerEmail = $record->customer?->getEmail();
        if ($customerEmail) {
            $options[$customerEmail] = 'Klant: ' . $record->getCustomerAddressDisplayName() . ' <' . $customerEmail . '>';
        }

        $fittingNote = $record->getFittingNote() ?? [];
        $dealerEmail = trim((string)($fittingNote['advisor_dealer_email'] ?? ''));
        if ($dealerEmail !== '' && filter_var($dealerEmail, FILTER_VALIDATE_EMAIL)) {
            $options[$dealerEmail] = 'Adviseur dealer: <' . $dealerEmail . '>';
        }

        return $options;
    }

    private static function mergeDealerContactIntoFittingNote(Main $record, ViewOrder $page, array $data): void
    {
        $note = $record->getFittingNote() ?? [];
        $notifyCustomer = self::isNotifyCustomerEnabledFromData($data);
        $dealerName = trim((string) ($data['dealer_name'] ?? ''));
        $dealerEmail = $notifyCustomer
            ? trim((string) ($data['dealer_email'] ?? ''))
            : '';
        $extraCc = $notifyCustomer
            ? array_values(array_filter((array)($data['extra_cc'] ?? []), fn($v) => is_string($v) && $v !== ''))
            : [];
        $extraBcc = $notifyCustomer
            ? array_values(array_filter((array)($data['extra_bcc'] ?? []), fn($v) => is_string($v) && $v !== ''))
            : [];

        if ($dealerName !== '') {
            $note['advisor_dealer_name'] = $dealerName;
        } else {
            unset($note['advisor_dealer_name']);
        }

        if ($dealerEmail !== '') {
            $note['advisor_dealer_email'] = $dealerEmail;
        } else {
            unset($note['advisor_dealer_email']);
        }

        if ($extraCc !== []) {
            $note['extra_cc'] = $extraCc;
        } else {
            unset($note['extra_cc']);
        }

        if ($extraBcc !== []) {
            $note['extra_bcc'] = $extraBcc;
        } else {
            unset($note['extra_bcc']);
        }

        $record->setFittingNote($note !== [] ? $note : null);

        $page->fittingNoteAdvisorDealerName = $dealerName;
        $page->fittingNoteAdvisorDealerEmail = $dealerEmail;
    }

}

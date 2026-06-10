<?php

namespace App\Filament\Actions;

use App\Enums\CreateMainMode;
use App\Enums\CustomerAddressType;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Filament\Resources\Mains\MainResource;
use App\Models\Customer;
use App\Models\Order\Main;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMainAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_main';
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create main orders') ?? false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->visible(fn (): bool => static::canCreate())
            ->label('Aanvraag aanmaken')
            ->icon('heroicon-s-plus-circle')
            ->modalHeading(function (): string {
                if ($this->getArguments()['from_dashboard_passing'] ?? false) {
                    return 'Nieuwe aanvraag: Passing';
                }

                if ($this->getArguments()['from_dashboard_quick_links'] ?? false) {
                    $mode = CreateMainMode::tryFrom((string) ($this->getArguments()['mode'] ?? CreateMainMode::Fitting->value));

                    return match ($mode) {
                        CreateMainMode::Quote => 'Nieuwe aanvraag: Offerte',
                        CreateMainMode::Order => 'Nieuwe aanvraag: Order',
                        default => 'Nieuwe aanvraag',
                    };
                }

                return 'Nieuwe aanvraag';
            })
            ->extraModalWindowAttributes(function (): array {
                $mode = CreateMainMode::tryFrom(
                    (string) ($this->getArguments()['mode'] ?? CreateMainMode::Fitting->value)
                ) ?? CreateMainMode::Fitting;

                $classes = ['create-main-modal'];

                if ($mode === CreateMainMode::Order) {
                    $classes[] = 'create-main-modal--order';
                }

                return ['class' => implode(' ', $classes)];
            })
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Aanmaken')
            ->fillForm(function (array $arguments): array {
                $mode = CreateMainMode::tryFrom((string) ($arguments['mode'] ?? CreateMainMode::Fitting->value)) ?? CreateMainMode::Fitting;
                $fromDashboardPassing = (bool) ($arguments['from_dashboard_passing'] ?? false);

                $subtype = OrderSubtype::Unit->value;
                if (! $fromDashboardPassing && $mode === CreateMainMode::Order) {
                    $subtype = OrderSubtype::Part->value;
                }

                return [
                    'mode' => $mode->value,
                    'subtype' => $subtype,
                ];
            })
            ->schema(function (): array {
                $fromDashboardPassing = (bool) ($this->getArguments()['from_dashboard_passing'] ?? false);

                return [
                    Hidden::make('mode')
                        ->default(CreateMainMode::Fitting->value)
                        ->dehydrated(),
                    ...MainResource::getCreateFormSchema($fromDashboardPassing),
                ];
            })
            ->action(function (array $data, array $arguments = []) {
                abort_unless(static::canCreate(), 403);

                $mode = CreateMainMode::tryFrom((string) (($arguments['mode'] ?? $data['mode']) ?? CreateMainMode::Fitting->value)) ?? CreateMainMode::Fitting;

                $main = DB::transaction(function () use ($data, $mode) {
                    $isNewCustomer = MainResource::isNewCustomerSelection($data['customer_or_dealer'] ?? null);

                    $customerId = isset($data['customer_id']) && $data['customer_id'] !== '' && (int) $data['customer_id'] !== 0
                        ? (int) $data['customer_id']
                        : null;

                    $billingRaw = $data['billing_customer_id'] ?? null;
                    $billingCustomerId = is_numeric($billingRaw) && (int) $billingRaw !== 0
                        ? (int) $billingRaw
                        : null;

                    if ($isNewCustomer) {
                        $customer = MainResource::createB2CCustomerFromCreateMainForm($data);
                        $customerId = (int) $customer->getKey();

                        if (
                            MainResource::isNewCustomerBillingSelection($billingRaw)
                            || $billingRaw === null
                            || $billingRaw === ''
                        ) {
                            $billingCustomerId = $customerId;
                        } else {
                            $billingCustomerId = (int) $billingRaw;
                        }

                        $data['customer_address_type'] = CustomerAddressType::Billing->value;
                        if (trim((string) ($data['delivery_address_type'] ?? '')) === '') {
                            $data['delivery_address_type'] = 'customer';
                        }
                    } else {
                        $selectedParty = $data['customer_or_dealer'] ?? null;
                        if ($customerId === null && is_string($selectedParty)) {
                            if (preg_match('/^(?:customer|dealer)-(\d+)(?:-shipping)?$/', $selectedParty, $m)) {
                                $customerId = (int) $m[1];
                            }
                        }

                        if ($billingCustomerId === null && $customerId !== null) {
                            $billingCustomerId = $customerId;
                        }

                        if ($customerId === null || $customerId === 0) {
                            throw ValidationException::withMessages([
                                'customer_or_dealer' => 'Selecteer een klant.',
                            ]);
                        }
                    }

                    if ($billingCustomerId === null || $billingCustomerId === 0) {
                        throw ValidationException::withMessages([
                            'billing_customer_id' => 'Factuurgegevens ontbreken.',
                        ]);
                    }

                    if (
                        $billingCustomerId !== $customerId
                    ) {
                        $isEligibleBillingCustomer = MainResource::queryBillingCustomersForNewMainSelect()
                            ->whereKey($billingCustomerId)
                            ->exists();
                        if (! $isEligibleBillingCustomer) {
                            throw ValidationException::withMessages([
                                'billing_customer_id' => 'Kies een geldige factuurklant.',
                            ]);
                        }
                    }

                    $targetStatus = match ($mode) {
                        CreateMainMode::Fitting => OrderStatus::FittingDraft,
                        CreateMainMode::Quote => OrderStatus::QuoteDraft,
                        CreateMainMode::Order => OrderStatus::OrderDraft,
                    };

                    if (
                        ($data['subtype'] ?? null) === OrderSubtype::Unit->value
                        && $targetStatus === OrderStatus::FittingDraft
                    ) {
                        $billingForSimplifiedStart = Customer::query()->find($billingCustomerId);
                        if (in_array($billingForSimplifiedStart?->getType(), Main::billingTypesForUnitSimplifiedSalesFlow(), true)) {
                            $targetStatus = OrderStatus::QuoteDraft;
                        }
                    }

                    if (
                        in_array($data['subtype'] ?? null, [OrderSubtype::Part->value, OrderSubtype::Service->value], true)
                        && $targetStatus === OrderStatus::FittingDraft
                    ) {
                        $targetStatus = OrderStatus::QuoteDraft;
                    }

                    $deliveryAddressType = isset($data['delivery_address_type'])
                        ? trim((string) $data['delivery_address_type'])
                        : '';
                    if ($deliveryAddressType === '') {
                        throw ValidationException::withMessages([
                            'delivery_address_type' => 'Selecteer levergegevens.',
                        ]);
                    }
                    if (! in_array($deliveryAddressType, ['customer', 'dealer', 'av'], true)) {
                        throw ValidationException::withMessages([
                            'delivery_address_type' => 'Ongeldige levergegevens.',
                        ]);
                    }

                    $shippingCustomerId = $this->resolveShippingCustomerId(
                        $customerId,
                        $billingCustomerId,
                        $deliveryAddressType
                    );

                    $subtypeForCreate = $data['subtype'] ?? null;

                    $billingForSubtype = Customer::query()->find((int) $billingCustomerId);
                    if (
                        $subtypeForCreate === OrderSubtype::Service->value
                        && $billingForSubtype?->getType() === CustomerType::B2B
                    ) {
                        throw ValidationException::withMessages([
                            'subtype' => 'Service met B2B-factuur is niet van toepassing.',
                        ]);
                    }

                    $customerAddressType = CustomerAddressType::tryFrom((string) ($data['customer_address_type'] ?? ''))
                        ?? CustomerAddressType::Billing;

                    $additional = [
                        'shipping_address_type_key' => $this->resolveShippingAddressTypeKey(
                            $customerId,
                            $shippingCustomerId,
                            $deliveryAddressType,
                        ),
                    ];

                    $main = Main::withoutGlobalScopes()->create([
                        'type'                  => OrderType::Main,
                        'uid'                   => Main::getNextMainUid(),
                        'order_status'          => $targetStatus,
                        'customer_id'           => $customerId,
                        'billing_customer_id'   => $billingCustomerId,
                        'shipping_customer_id'  => $shippingCustomerId,
                        'customer_address_type' => $customerAddressType,
                        'subtype'               => $data['subtype'] ?? OrderSubtype::Unit->value,
                        'additional'            => $additional,
                    ]);

                    $main->save();

                    $main->orderEvents()->create([
                        'type'    => 'Aanvraag ' . $main->getUid() . ' aangemaakt',
                        'data'    => [],
                        'user_id' => Auth::id(),
                    ]);

                    $note = $main->getFittingNote() ?? [];

                    if (! empty($customerId)) {
                        $customer = Customer::query()->find((int) $customerId);
                        if ($customer?->dob) {
                            $note['birth_date'] = $customer->dob->format('Y-m-d');
                        }
                    }

                    if (filled($data['linked_serial_number'] ?? null)) {
                        $note['linked_serial_number'] = $data['linked_serial_number'];
                    }

                    if ($note !== []) {
                        $main->setFittingNote($note);
                        $main->save();
                    }

                    return $main;
                });

                Notification::make()
                    ->title("Aanvraag #{$main->getUid()} is aangemaakt")
                    ->success()
                    ->send();

                $mainPhase = OrderStatus::getMainStatusFor($main->getOrderStatus() ?? OrderStatus::FittingDraft);
                $tab = match ($mainPhase) {
                    OrderStatus::Fitting => 'fitting',
                    OrderStatus::Quote, OrderStatus::Order => 'order',
                    default => 'order',
                };

                $this->redirect(route('filament.app.resources.mains.view', ['record' => $main->getId()], true) . "?tab={$tab}");
            })
            ->modalCancelAction(fn ($action) => $action->extraAttributes(['class' => 'white']));
    }

    private function resolveShippingCustomerId(int $customerId, int $billingCustomerId, string $deliveryAddressType): int
    {
        return match ($deliveryAddressType) {
            'av' => (int) Customer::getAvCustomer()->getKey(),
            'customer' => $customerId,
            'dealer' => $billingCustomerId,
        };
    }

    private function resolveShippingAddressTypeKey(int $customerId, int $shippingCustomerId, string $deliveryAddressType): string
    {
        if ($deliveryAddressType === 'av') {
            return 'av';
        }

        if ((int) $shippingCustomerId === (int) $customerId) {
            return 'customer';
        }

        return 'customer-' . $shippingCustomerId;
    }
}

<?php

namespace App\Filament\Resources\CustomerResource\Widgets;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Enums\ValidityPeriod;
use App\Models\Customer;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\Order\Quote;
use App\Models\Product;
use App\Models\SerialNumber;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class CustomerUnitsWidget extends Widget implements HasSchemas, HasActions
{
    use InteractsWithSchemas;
    use InteractsWithActions;

    protected string $view = 'filament.widgets.customer-units-widget';
    protected static ?string $model = SerialNumber::class;

    public ?Model $record = null;


    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SerialNumber>
     */
    protected function getSerialNumbers()
    {
        if (! $this->record instanceof Customer) {
            return SerialNumber::query()->whereRaw('0')->get();
        }

        $customerId  = $this->record->id;
        $debtorNumber = $this->record->debtor_number;

        return SerialNumber::query()
            ->where('order_sub_type', OrderSubtype::Unit->value)
            ->where(function ($query) use ($customerId, $debtorNumber): void {
                $query->where('owner_id', $customerId);

                // Also include historical records matched by debtor number that
                // have not yet been linked to a customer via owner_id.
                if (filled($debtorNumber)) {
                    $query->orWhere(function ($q) use ($debtorNumber): void {
                        $q->whereNull('owner_id')
                            ->where('customer_debtor_number', $debtorNumber);
                    });
                }
            })
            ->with(['order.main', 'order.orderProducts.product'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function getHistoryModalAction(?SerialNumber $serialNumber): Action
    {
        $events = $serialNumber->serialNumberEvents()->with('user')->orderByDesc('created_at')->get();

        return Action::make('history')
            ->icon('heroicon-s-clock')
            ->iconButton()
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalHeading('Serienummer historie')
            ->modalContent(view('filament.resources.orders.partials.serial-number-events-table', [
                'serialNumberEvents' => $events,
            ]))
            ->modalFooterActions([])
            ->extraAttributes(['class' => 'historyAction']);
    }

    public function getTransferModalAction(?SerialNumber $serialNumber): Action
    {
        return Action::make('transfer')
            ->label('Overdragen')
            ->extraAttributes(['style' => 'padding: 5px 10px !important; font-size: 13px !important;'])
            ->modalWidth(Width::Small)
            ->modalHeading('Serienummer overdragen')
            ->schema([
                Select::make('customer_id')
                    ->label('Klant')
                    ->extraFieldWrapperAttributes(['class' => 'link-in-label'])
                    ->columnSpanFull()
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        return Customer::query()
                            ->active()
                            ->where(function ($query) use ($search) {
                                $query->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(100)
                            ->get()
                            ->unique('email')
                            ->take(50)
                            ->mapWithKeys(fn (Customer $customer) => [
                                $customer->id => $customer->getName() . ' (' . $customer->email . ')',
                            ]);
                    })
                    ->getOptionLabelUsing(function ($value) {
                        $customer = Customer::query()->find($value);

                        return $customer ? $customer->getName() . ' (' . $customer->email . ')' : '';
                    }),

                Textarea::make('reason')
                    ->label('Reden voor overdraging')
                    ->rows(5)
                    ->required(),
            ])
            ->action(function (array $data) use ($serialNumber) {
                if (!$serialNumber) return;

                $oldOwner = $serialNumber->owner;

                $customer = Customer::query()->find($data['customer_id']);

                $serialNumber->setOwnerId($customer->id);
                $serialNumber->save();

                $eventDescription = 'Overgedragen van ' . $oldOwner->getName() . ' naar ' . $customer->getName();
                if (!empty($data['reason'])) {
                    $eventDescription .= ' met reden: ' . $data['reason'];
                }

                $serialNumber->serialNumberEvents()->create([
                    'type' => $eventDescription,
                    'data' => [
                        'old_owner_id' => $oldOwner->id,
                        'new_owner_id' => $customer->id,
                    ],
                    'user_id' => Auth::id(),
                ]);

                Notification::make()
                    ->title('Serienummer overgedragen aan ' . $customer->getName())
                    ->success()
                    ->send();

                redirect()->route('filament.app.resources.customers.edit', [
                    'record' => $customer->id,
                    'tab' => 'units',
                ]);
            })
            ->modalSubmitActionLabel('Opslaan');
    }

    public function unitsInfolist(Schema $schema): Schema
    {
        $serialNumbers = $this->getSerialNumbers();
        $components = [];

        foreach ($serialNumbers as $serialNumber) {
            $serialNumberValue = $serialNumber->getSerialNumber();
            $totalAmount = SerialNumber::totalCostForSerialNumber($serialNumberValue);
            $ledgerEntries = SerialNumber::ledgerEntriesForSerialNumber($serialNumberValue);

            $components[] = Section::make('serial')
                ->key($serialNumber?->id)
                ->heading($serialNumber->getSerialNumber())
                ->headerActions([
                    $this->getHistoryModalAction($serialNumber),

                    Action::make('total_amount')
                        ->label(new HtmlString(
                            'TCO <span style="font-size: 9px">(incl. BTW)</span>: '
                            . (! empty($totalAmount) ? '€ ' . format_money_amount($totalAmount) : '')
                        ))
                        ->link()
                        ->extraAttributes(['class' => 'textAction']),

                    $this->getTransferModalAction($serialNumber),
                ])
                ->schema([
                    View::make('filament.components.customer-units-widget-table')
                        ->viewData([
                            'serialNumber' => $serialNumber,
                            'ledgerEntries' => $ledgerEntries,
                        ]),
                ])
                ->extraAttributes(['class' => 'unit-section']);
        }

        if ($serialNumbers?->isEmpty() ?? false) {
            $components[] = EmptyState::make('Geen units')
                ->icon('heroicon-s-x-mark')
                ->iconColor('gray')
                ->extraAttributes(['class' => 'fi-ta-empty-state-icon-bg']);
        }

        return $schema
            ->components($components);
    }

    #[On('create-quote-or-order')]
    public function createQuoteOrOrder(string $type, string $initData): void
    {
        if (empty($type) || empty($initData)) {
            return;
        }

        $decoded = json_decode(base64_decode($initData, true), true);

        if ($type === 'quote' && is_array($decoded)) {
            $this->createMainWithQuoteAndRedirect($decoded);

            return;
        }

        if ($type === 'order' && is_array($decoded)) {
            $this->createMainWithOrderAndRedirect($decoded);

            return;
        }
    }

    /**
     * Create a new Main with a linked Quote for the given unit product,
     * then redirect to the quote edit page — identical to the "Offerte toevoegen" flow
     * within an existing aanvraag. Part/Service: main status Order: Concept (geen Offerte-fase).
     *
     * @param  array{subtype?: string, customer_id?: int|string, billing_customer_id?: int|string|null, product_id?: int|string|null}  $data
     */
    private function createMainWithQuoteAndRedirect(array $data): void
    {
        $customerId        = isset($data['customer_id'])        ? (int) $data['customer_id']        : null;
        $billingCustomerId = isset($data['billing_customer_id']) ? (int) $data['billing_customer_id'] : null;
        $subtype           = $data['subtype'] ?? OrderSubtype::Part->value;
        $productId         = isset($data['product_id'])         ? (int) $data['product_id']         : null;

        $quote = DB::transaction(function () use ($customerId, $billingCustomerId, $subtype, $productId): Quote {
            $subtypeEnum = OrderSubtype::tryFrom((string) $subtype) ?? OrderSubtype::Part;
            $initialMainStatus = in_array($subtypeEnum, [OrderSubtype::Part, OrderSubtype::Service], true)
                ? OrderStatus::OrderDraft
                : OrderStatus::QuoteDraft;

            $main = Main::withoutGlobalScopes()->create([
                'type'                => OrderType::Main,
                'uid'                 => Main::getNextMainUid(),
                'order_status'        => $initialMainStatus,
                'customer_id'         => $customerId,
                'billing_customer_id' => $billingCustomerId,
                'shipping_customer_id'=> $billingCustomerId,
                'subtype'             => $subtype,
            ]);

            $main->orderEvents()->create([
                'type'    => 'Aanvraag ' . $main->getUid() . ' aangemaakt',
                'data'    => [],
                'user_id' => Auth::id(),
            ]);

            $main->refresh();

            $additional = [];
            $conditionCode = $main->getExactPaymentConditionInheritedByChildren();
            if ($conditionCode !== '') {
                $additional['exact_payment_condition'] = $conditionCode;
            }

            $quote = Quote::withoutGlobalScopes()->create([
                'type'                => OrderType::Quote->value,
                'main_id'             => $main->getId(),
                'customer_id'         => $main->getCustomerId(),
                'billing_customer_id' => $billingCustomerId,
                'shipping_customer_id'=> $billingCustomerId,
                'validity_period'     => ValidityPeriod::DAYS_60,
                'reference'           => $main->getUid(),
                'subtype'             => $main->getSubtype()?->value,
                'advisor_id'          => $main->getAdvisorId(),
                'status'              => OrderGeneralStatus::Initial,
                'payment_terms'       => $main->getPaymentTermsInheritedByChildren(),
                'additional'          => $additional ?: null,
            ]);

            $quote->save();

            $main->setQuoteCreatedAt(now());
            $main->save();

            // 3. Pre-populate the product on the quote
            $product = $productId !== null ? Product::query()->find($productId) : null;
            if ($product) {
                $orderProduct = $quote->orderProducts()->create([
                    'product_id'                       => $product->getId(),
                    'value'                            => $product->getName(),
                    'qty'                              => 1,
                    'company_purchase_price_base'      => round($product->getCompanyPurchasePrice(), 2),
                    'company_purchase_price_additional'=> 0,
                    'company_purchase_price_subtotal'  => round($product->getCompanyPurchasePrice(), 2),
                    'company_sales_price_base'         => round($product->getCompanySalesPrice(), 2),
                    'company_sales_price_additional'   => 0,
                    'company_sales_price_subtotal'     => round($product->getCompanySalesPrice(), 2),
                    'vat'                              => 21,
                    'supplier_id'                      => $product->supplier?->id,
                    'order_id'                         => null,
                ]);
                $orderProduct->setFulfillmentTypeBasedOnProduct()->save();
            }

            return $quote;
        });

        $this->redirect(route('filament.app.resources.quotes.edit', ['record' => $quote->id]));
    }

    /**
     * Create a new Main (OrderDraft) with a linked Order for the given unit product,
     * then redirect to the order edit page — identical to the "Order aanmaken" flow.
     *
     * @param  array{subtype?: string, customer_id?: int|string, billing_customer_id?: int|string|null, product_id?: int|string|null}  $data
     */
    private function createMainWithOrderAndRedirect(array $data): void
    {
        $customerId        = isset($data['customer_id'])        ? (int) $data['customer_id']        : null;
        $billingCustomerId = isset($data['billing_customer_id']) ? (int) $data['billing_customer_id'] : null;
        $subtype           = $data['subtype'] ?? OrderSubtype::Part->value;
        $productId         = isset($data['product_id'])         ? (int) $data['product_id']         : null;

        $order = DB::transaction(function () use ($customerId, $billingCustomerId, $subtype, $productId): Order {
            $main = Main::withoutGlobalScopes()->create([
                'type'                => OrderType::Main,
                'uid'                 => Main::getNextMainUid(),
                'order_status'        => OrderStatus::OrderDraft,
                'customer_id'         => $customerId,
                'billing_customer_id' => $billingCustomerId,
                'shipping_customer_id'=> $billingCustomerId,
                'subtype'             => $subtype,
            ]);

            $main->orderEvents()->create([
                'type'    => 'Aanvraag ' . $main->getUid() . ' aangemaakt',
                'data'    => [],
                'user_id' => Auth::id(),
            ]);

            $main->refresh();

            $additional = [];
            $conditionCode = $main->getExactPaymentConditionInheritedByChildren();
            if ($conditionCode !== '') {
                $additional['exact_payment_condition'] = $conditionCode;
            }

            $order = Order::withoutGlobalScopes()->create([
                'type'                => OrderType::Order->value,
                'main_id'             => $main->getId(),
                'customer_id'         => $main->getCustomerId(),
                'billing_customer_id' => $billingCustomerId,
                'shipping_customer_id'=> $billingCustomerId,
                'reference'           => $main->getUid(),
                'subtype'             => $main->getSubtype()?->value,
                'advisor_id'          => $main->getAdvisorId(),
                'status'              => OrderGeneralStatus::Initial,
                'order_status'        => OrderStatus::Order,
                'payment_terms'       => $main->getPaymentTermsInheritedByChildren(),
                'additional'          => $additional ?: null,
            ]);

            $order->save();

            // 3. Pre-populate the product on the order
            $product = $productId !== null ? Product::query()->find($productId) : null;
            if ($product) {
                $orderProduct = $order->orderProducts()->create([
                    'product_id'                        => $product->getId(),
                    'value'                             => $product->getName(),
                    'qty'                               => 1,
                    'company_purchase_price_base'       => round($product->getCompanyPurchasePrice(), 2),
                    'company_purchase_price_additional' => 0,
                    'company_purchase_price_subtotal'   => round($product->getCompanyPurchasePrice(), 2),
                    'company_sales_price_base'          => round($product->getCompanySalesPrice(), 2),
                    'company_sales_price_additional'    => 0,
                    'company_sales_price_subtotal'      => round($product->getCompanySalesPrice(), 2),
                    'vat'                               => 21,
                    'supplier_id'                       => $product->supplier?->id,
                    'order_id'                          => null,
                ]);
                $orderProduct->setFulfillmentTypeBasedOnProduct()->save();
            }

            return $order;
        });

        $this->redirect(route('filament.app.resources.orders.edit', ['record' => $order->id]));
    }

}

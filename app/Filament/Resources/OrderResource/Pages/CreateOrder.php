<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Enums\CustomerType;
use App\Enums\OrderGeneralStatus;
use App\Enums\PaymentTerms;
use App\Filament\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order\Main;
use App\Models\Order\Order;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Section;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected static ?string $title = 'Verkooporder';

    protected static ?string $breadcrumb = 'Aanmaken verkooporder';

    public static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $mainId = request()->query('main_id');
        if ($mainId) {
            $main = Main::withoutGlobalScopes()->find($mainId);
            if ($main && ($main->billing_customer_id || $main->getCustomerId())) {
                $this->createAndRedirect(
                    customerId: $main->getCustomerId(),
                    billingCustomerId: $main->billing_customer_id,
                    shippingCustomerId: $main->shipping_customer_id,
                    main: $main
                );

                return;
            }
        }

        $initData = request()->query('initData');
        $decodedInitData = base64_decode($initData, true);
        if (empty($decodedInitData)) {
            return;
        }
        $initDataJson = json_decode($decodedInitData, true);
        if ($initDataJson) {
            $customerId = isset($initDataJson['customer_id']) ? (int) $initDataJson['customer_id'] : null;
            $this->createAndRedirect(
                customerId: $customerId,
                billingCustomerId: $customerId,
                shippingCustomerId: $customerId,
                initData: [
                    'subtype'     => $initDataJson['subtype'] ?? null,
                    'customer_id' => $customerId,
                    'product_id'  => $initDataJson['product_id'] ?? null,
                ],
            );

            return;
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function getCustomerOrDealerOptions(): array
    {
        return Customer::query()
            ->active()
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (Customer $c): array => [$c->id => $c->getName()])
            ->all();
    }

    private function searchCustomerOrDealerOptions(string $search): array
    {
        return Customer::query()
            ->active()
            ->whereIn('type', array_keys(CustomerType::visibleLabels()))
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Customer $c): array => [$c->id => $c->getName()])
            ->all();
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Order-overzicht',
                        'url' => route('filament.app.resources.orders.index'),
                    ]),

                Section::make('Nieuwe verkooporder')
                    ->columns(12)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Group::make()
                            ->columnSpan(6)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                Select::make('relation_id')
                                    ->label('Ter attentie van')
                                    ->inlineLabel()
                                    ->options(fn () => $this->getCustomerOrDealerOptions())
                                    ->getSearchResultsUsing(fn (string $search) => $this->searchCustomerOrDealerOptions($search))
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => 'Selecteer een klant of dealer.',
                                    ])
                                    ->columnSpanFull()
                                    ->selectablePlaceholder(false)
                                    ->live()
                                    ->extraAttributes(['class' => 'ter-attentie-van-field'])
                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap ter-attentie-van-field'])
                                    ->afterStateUpdated(function ($state): void {
                                        if (! filled($state)) {
                                            return;
                                        }

                                        $customer = Customer::query()->find((int) $state);
                                        if (! $customer) {
                                            return;
                                        }

                                        if ($customer->getType() === CustomerType::B2B) {
                                            $this->createAndRedirect(
                                                customerId: null,
                                                billingCustomerId: (int) $state,
                                                shippingCustomerId: (int) $state,
                                            );
                                        } else {
                                            $this->createAndRedirect(
                                                customerId: (int) $state,
                                                billingCustomerId: (int) $state,
                                                shippingCustomerId: (int) $state,
                                            );
                                        }
                                    }),
                            ]),
                    ]),
            ]);
    }

    public function createAndRedirect(
        ?int $customerId = null,
        ?int $billingCustomerId = null,
        ?int $shippingCustomerId = null,
        ?array $initData = null,
        ?Main $main = null,
    ): void {
        $order = $this->createDraftOrder($customerId, $billingCustomerId, $shippingCustomerId, $initData, $main);

        $this->redirect(route('filament.app.resources.orders.edit', ['record' => $order->id]));
    }

    public function createDraftOrder(
        ?int $customerId = null,
        ?int $billingCustomerId = null,
        ?int $shippingCustomerId = null,
        ?array $initData = null,
        ?Main $main = null,
    ): Order {
        /** @var Order $order */
        $order = Order::withoutGlobalScopes()->create([
            'type'                 => 'order',
            'customer_id'          => $customerId,
            'billing_customer_id'  => $billingCustomerId,
            'shipping_customer_id' => $shippingCustomerId ?? $billingCustomerId,
            'status'               => OrderGeneralStatus::Initial,
            'payment_terms'        => PaymentTerms::Postpay->value,
        ]);

        if ($initData) {
            $order->setSubtype($initData['subtype'] ?? null);
            $order->setCustomerId(isset($initData['customer_id']) ? (int) $initData['customer_id'] : $customerId);

            $product = Product::query()->find($initData['product_id'] ?? null);
            if ($product) {
                $orderProduct = $order->orderProducts()->create([
                    'product_id'                         => $product->getId(),
                    'value'                              => $product->getName(),
                    'qty'                                => 1,
                    'company_purchase_price_base'        => round($product->getCompanyPurchasePrice(), 2),
                    'company_purchase_price_additional'  => 0,
                    'company_purchase_price_subtotal'    => round($product->getCompanyPurchasePrice(), 2),
                    'company_sales_price_base'           => round($product->getCompanySalesPrice(), 2),
                    'company_sales_price_additional'     => 0,
                    'company_sales_price_subtotal'       => round($product->getCompanySalesPrice(), 2),
                    'vat'                                => 21,
                    'supplier_id'                        => $product->supplier?->id,
                    'order_id'                           => null,
                ]);
                $orderProduct
                    ->setFulfillmentTypeBasedOnProduct()
                    ->save();
            }
        }

        $termsString = $main !== null
            ? $main->getPaymentTermsValueForBillingContext()
            : $order->getPaymentTermsValueForBillingContext();
        $order->payment_terms = PaymentTerms::tryFrom($termsString) ?? PaymentTerms::Postpay;
        $order->resetOrderDateToToday()->save();

        return $order;
    }
}

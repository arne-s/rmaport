<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Enums\CustomerType;
use App\Enums\DeliveryTime;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\PaymentTerms;
use App\Filament\Resources\QuoteResource;
use App\Models\Customer;
use App\Models\Order\Quote;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected static ?string $title = 'Offerte';

    protected static ?string $breadcrumb = 'Aanmaken offerte';

    public static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        $initData = request()->query('initData');
        $decodedInitData = base64_decode($initData, true);
        if (empty($decodedInitData)) {
            return;
        }
        $initDataJson = json_decode($decodedInitData, true);
        if ($initDataJson) {
            $customerId = isset($initDataJson['customer_id']) ? (int) $initDataJson['customer_id'] : null;
            $this->createFromRelationAndRedirect([
                'subtype'     => $initDataJson['subtype'] ?? null,
                'customer_id' => $customerId,
                'product_id'  => $initDataJson['product_id'] ?? null,
            ]);

            return;
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function createFromRelationAndRedirect(array $data): void
    {
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $billingCustomerId = isset($data['billing_customer_id']) ? (int) $data['billing_customer_id'] : $customerId;

        $quote = Quote::create([
            'type'                 => 'quote',
            'customer_id'          => $customerId,
            'billing_customer_id'  => $billingCustomerId,
            'shipping_customer_id' => $billingCustomerId ?? $customerId,
            'status'               => OrderGeneralStatus::Initial,
            'payment_terms'        => PaymentTerms::Postpay->value,
        ]);

        if (! empty($data['subtype'])) {
            $quote->setSubtype($data['subtype']);
        }
        $quote->setCustomerId($customerId);

        $quote->payment_terms = PaymentTerms::tryFrom($quote->getPaymentTermsValueForBillingContext()) ?? PaymentTerms::Postpay;

        if (($data['subtype'] ?? null) === OrderSubtype::Unit->value || empty($data['subtype'])) {
            $quote->setAdditional(array_merge($quote->getAdditional() ?? [], [
                'delivery_time' => DeliveryTime::ThirteenWeeks->value,
            ]));
        }

        $product = Product::query()->find($data['product_id'] ?? null);
        if ($product) {
            $orderProduct = $quote->orderProducts()->create([
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
            $orderProduct
                ->setFulfillmentTypeBasedOnProduct()
                ->save();
        }
        $quote->save();

        $this->redirect(route('filament.app.resources.quotes.edit', ['record' => $quote->id]));
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Offerte-overzicht',
                        'url' => route('filament.app.resources.quotes.index'),
                    ]),

                Section::make('Nieuwe offerte')
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
                                            $this->createFromRelationAndRedirect([
                                                'customer_id'         => null,
                                                'billing_customer_id' => (int) $state,
                                            ]);
                                        } else {
                                            $this->createFromRelationAndRedirect([
                                                'customer_id' => (int) $state,
                                            ]);
                                        }
                                    }),
                            ]),
                    ]),
            ]);
    }
}

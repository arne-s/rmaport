<?php

namespace App\Filament\Resources\StockOrderResource\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\StockOrderResource;
use App\Models\Customer;
use App\Models\Order\StockOrder;
use App\Models\Supplier;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Section;
use Filament\Resources\Pages\CreateRecord;

class CreateStockOrder extends CreateRecord
{
    protected static string $resource = StockOrderResource::class;

    protected static ?string $title = 'Inkooporder';

    protected static ?string $breadcrumb = 'Aanmaken inkooporder';

    public static bool $canCreateAnother = false;

    protected function getHeaderActions(): array
    {
        return [];
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
                        'title' => 'Inkooporder-overzicht',
                        'url' => route('filament.app.resources.purchase-orders.confirmed'),
                    ]),

                Section::make('Nieuwe inkooporder')
                    ->columns(2)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Group::make()
                            ->columnSpan(1)
                            ->extraAttributes(['class' => 'custom-form-design'])
                            ->schema([
                                Select::make('supplier_id')
                                    ->label('Ter attentie van')
                                    ->inlineLabel()
                                    ->options(
                                        Supplier::query()
                                            ->orderBy('name')
                                            ->get()
                                            ->mapWithKeys(fn (Supplier $s) => [$s->id => $s->name])
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->validationMessages([
                                        'required' => 'Selecteer een leverancier.',
                                    ])
                                    ->columnSpanFull()
                                    ->selectablePlaceholder(false)
                                    ->live()
                                    ->extraFieldWrapperAttributes(['class' => 'whitespace-nowrap ter-attentie-van-field'])
                                    ->afterStateUpdated(fn ($state) => $this->createAndRedirect($state)),
                            ]),
                    ]),
            ]);
    }

    public function createAndRedirect($supplierId): void
    {
        if (!$supplierId) return;

        $stockOrder = $this->createStockOrder($supplierId);
        $this->redirect(route('filament.app.resources.stock-orders.edit', ['record' => $stockOrder->id]));
    }

    public function createStockOrder($supplierId): StockOrder
    {
        /** @var StockOrder $stockOrder */
        $rdCustomer = Customer::getRdMobilityCustomer();
        $shippingSource = $rdCustomer->billingAddress;
        $shippingName = $rdCustomer->getName();

        $stockOrder = StockOrder::create([
            'type'                => 'stock_order',
            'billing_customer_id' => $rdCustomer->id,
            'shipping_customer_id' => $rdCustomer->id,
            'status'              => PurchaseOrderStatus::Draft,
            'author_id'           => auth()->id(),
        ]);
        $stockOrder->setSupplierId((int) $supplierId);

        $additional = $stockOrder->getAdditional() ?? [];

        if ($shippingSource !== null) {
            $addressSnapshot = [
                'street'               => $shippingSource->street,
                'house_number'         => $shippingSource->house_number,
                'house_number_addition'=> $shippingSource->house_number_addition,
                'postcode'             => $shippingSource->postcode,
                'city'                 => $shippingSource->city,
                'country_id'           => $shippingSource->country_id,
            ];

            $additional = array_merge($additional, [
                'billing_address_type_key'  => 'rd',
                'billing_name'              => $shippingName,
                'invoice_address'           => $addressSnapshot,
                'shipping_address_type_key' => 'rd',
                'shipping_name'             => $shippingName,
                'delivery_address'          => $addressSnapshot,
            ]);
        }

        $stockOrder->setAdditional($additional);
        $stockOrder->save();

        return $stockOrder;
    }
}

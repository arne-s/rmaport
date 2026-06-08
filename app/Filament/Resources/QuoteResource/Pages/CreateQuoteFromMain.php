<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Enums\DeliveryTime;
use App\Enums\OrderGeneralStatus;
use App\Enums\OrderSubtype;
use App\Enums\OrderType;
use App\Enums\ValidityPeriod;
use App\Filament\Resources\QuoteResource;
use App\Models\Order\Main;
use App\Models\Order\Quote;
use Filament\Resources\Pages\Page;

class CreateQuoteFromMain extends Page
{
    protected static string $resource = QuoteResource::class;

    protected static ?string $title = 'Offerte';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $main): void
    {
        $mainOrder = Main::withoutGlobalScopes()->find($main);
        if ($mainOrder === null) {
            $this->redirect(route('filament.app.resources.quotes.index'), navigate: true);

            return;
        }

        $existingQuote = Quote::withoutGlobalScopes()
            ->where('main_id', $mainOrder->getId())
            ->where('type', OrderType::Quote)
            ->where('status', OrderGeneralStatus::Draft)
            ->orderByDesc('id')
            ->first();

        if ($existingQuote !== null) {
            $this->redirect(route('filament.app.resources.quotes.edit', ['record' => $existingQuote->id], true), navigate: true);

            return;
        }

        $quote = $this->createQuoteFromMain($mainOrder);

        $this->redirect(route('filament.app.resources.quotes.edit', ['record' => $quote->id], true), navigate: true);
    }

    protected function createQuoteFromMain(Main $main): Quote
    {
        $additional = [];
        if ($main->getSubtype() === OrderSubtype::Unit) {
            $additional['delivery_time'] = DeliveryTime::ThirteenWeeks->value;
        }
        $conditionCode = $main->getExactPaymentConditionInheritedByChildren();
        if ($conditionCode !== '') {
            $additional['exact_payment_condition'] = $conditionCode;
        }

        $quote = Quote::withoutGlobalScopes()->create([
            'type'                  => 'quote',
            'main_id'               => $main->getId(),
            'customer_id'           => $main->getCustomerId(),
            'billing_customer_id'   => $main->billing_customer_id,
            'shipping_customer_id'  => $main->shipping_customer_id ?? $main->billing_customer_id,
            'customer_address_type' => $main->getCustomerAddressType(),
            'validity_period'       => ValidityPeriod::DAYS_60,
            'reference'             => $main->getUid(),
            'subtype'               => $main->getSubtype()?->value,
            'advisor_id'            => $main?->getAdvisorId(),
            'status'                => OrderGeneralStatus::Initial,
            'payment_terms'         => $main->getPaymentTermsInheritedByChildren(),
            'additional'            => $additional ?: null,
        ]);

        $quote->save();

        $main->setQuoteCreatedAt(now());
        $main->save();

        return $quote;
    }
}

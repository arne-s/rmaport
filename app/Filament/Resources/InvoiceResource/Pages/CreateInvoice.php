<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderType;
use App\Enums\PaymentTerms;
use App\Filament\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\Order\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static ?string $title = 'Factuur aanmaken';

    protected static ?string $breadcrumb = 'Factuur aanmaken';

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
                        'title' => 'Facturen',
                        'url' => route('filament.app.resources.invoices.index'),
                    ]),

                Section::make('Nieuwe factuur')
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
                                    ->options(fn () => Customer::query()
                                        ->active()
                                        ->whereNotNull('type')
                                        ->orderBy('name')
                                        ->orderBy('first_name')
                                        ->get()
                                        ->mapWithKeys(fn (Customer $c): array => [$c->id => $c->getName()])
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->validationMessages([
                                        'required' => 'Selecteer een klant.',
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

                                        $this->createInvoiceAndRedirect(customerId: (int) $state);
                                    }),
                            ]),
                    ]),
            ]);
    }

    public function createInvoiceAndRedirect(int $customerId): void
    {
        $invoice = $this->createDraftInvoice($customerId);

        $this->redirect(EditInvoice::getUrl(['record' => $invoice->getKey()]));
    }

    public function createDraftInvoice(int $customerId): Invoice
    {
        $customer = Customer::query()->find($customerId);

        /** @var Invoice $invoice */
        $invoice = Invoice::withoutGlobalScopes()->create([
            'type'                 => OrderType::Invoice->value,
            'customer_id'          => $customerId,
            'shipping_customer_id' => $customerId,
            'billing_customer_id'  => $customerId,
            'status'               => OrderGeneralStatus::Initial->value,
            'main_id'              => null,
            'order_id'             => null,
            'payment_terms'        => $customer?->getPaymentTerms()?->value ?? PaymentTerms::Postpay->value,
        ]);

        return $invoice;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Doorgaan naar factuur')
            ->hidden();
    }

    protected function getRedirectUrl(): string
    {
        return EditInvoice::getUrl(['record' => $this->record]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $raw = $data['relation_id'] ?? null;
        if (is_numeric($raw)) {
            return $this->createDraftInvoice((int) $raw);
        }
        if (! is_string($raw) || $raw === '') {
            throw ValidationException::withMessages([
                'relation_id' => 'Selecteer een klant of dealer.',
            ]);
        }
        if (str_starts_with($raw, 'customer-')) {
            return $this->createDraftInvoice((int) substr($raw, 9));
        }
        if (str_starts_with($raw, 'company-')) {
            return $this->createDraftInvoice((int) substr($raw, 8));
        }

        throw ValidationException::withMessages([
            'relation_id' => 'Ongeldige keuze.',
        ]);
    }
}

<?php

namespace App\Filament\Resources\CreditInvoiceResource\Pages;

use App\Actions\SendCreditInvoiceMailAction;
use App\Filament\Concerns\HasSalesOrderProductRepeaterHelpers;
use App\Filament\Concerns\ManagesRecordLock;
use App\Filament\Support\RecordLockEditPage;
use App\Filament\Resources\CreditInvoiceResource;
use App\Filament\Resources\CreditInvoiceResource\Actions\SubmitCreditInvoiceEmailAction;
use App\Jobs\SyncInvoiceToExactJob;
use App\Models\AppSyncMessage;
use App\Models\ExactVATCode;
use App\Models\Order\CreditInvoice;
use App\Models\OrderProduct;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use App\Filament\Forms\Components\OrderProductsRepeater;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * @property CreditInvoice $record
 */
class EditCreditInvoice extends EditRecord
{
    use HasSalesOrderProductRepeaterHelpers;
    use ManagesRecordLock;

    protected static string $resource = CreditInvoiceResource::class;

    protected string $view = RecordLockEditPage::VIEW;

    public float $credited = 0;
    public float $paid = 0;
    public float $discountAmountExVat = 0;
    public float $vatPercentage = 21.0;

    /** @var Collection<int, array> */
    public ?Collection $orderProducts = null;

    protected function resolveRecord($key): CreditInvoice
    {
        return CreditInvoice::findOrFail($key);
    }

    protected function getFormModel(): string
    {
        return CreditInvoice::class;
    }

    public function mount(int|string $record): void
    {
        if (! $this->mountRecordLockGate($record)) {
            return;
        }

        parent::mount($record);

        $this->completeRecordLockMount();

        $this->orderProducts ??= collect();
        $this->vatPercentage = $this->resolveVatPercentage();

        $vatFactor = 1 + ($this->vatPercentage / 100);
        $amount = abs((float) ($this->data['discount_amount'] ?? 0));
        $this->data['discount_amount_calc'] = $amount / $vatFactor;

        $this->loadOrderProducts();
        $this->syncValues();
    }

    protected function resolveVatPercentage(): float
    {
        $parentInvoice = $this->record->invoice;
        if ($parentInvoice) {
            $vatCode = ($parentInvoice->getAdditional() ?? [])['exact_vat_code'] ?? null;
            if ($vatCode !== null && $vatCode !== '') {
                $exactVat = ExactVATCode::where('code', $vatCode)->first();
                if ($exactVat !== null) {
                    $pct = (float) $exactVat->percentage;

                    return $pct < 1 ? $pct * 100 : $pct;
                }
            }
        }

        return 21.0;
    }

    protected function getRedirectUrl(): string
    {
        return route('filament.app.resources.credit-invoices.index');
    }

    protected function getFormActions(): array
    {
        return $this->formActionsUnlessRecordLockBlocked([
            SubmitCreditInvoiceEmailAction::make()
                ->disabled(fn () => $this->credited >= 0)
                ->extraAttributes(['id' => 'save-button']),

            Action::make('cancel')
                ->label('Annuleren')
                ->action(fn () => $this->redirect($this->getRedirectUrl()))
                ->extraAttributes(['class' => 'white']),
        ]);
    }

    public function updated(): void
    {
        $this->syncValues();
    }

    protected function persistCreditInvoiceData(): void
    {
        $discountExVat = (float) ($this->data['discount_amount_calc'] ?? 0);

        $this->syncValues();

        $this->record->setPaymentAmount((float) ($this->data['payment_amount'] ?? 0));
        $this->record->setCompanySalesPriceDiscount($discountExVat);
        $this->record->save();

        if (isset($this->data['order_products'])) {
            foreach ($this->data['order_products'] as $orderProductData) {
                $id = $orderProductData['id'] ?? null;
                if (!$id) {
                    continue;
                }

                $op = OrderProduct::find($id);
                if (!$op) {
                    continue;
                }

                $op->setHasCredit((bool) ($orderProductData['has_credit'] ?? false));
                $op->setCompanySalesPriceCredited((float) ($orderProductData['credited_amount'] ?? 0));
                $op->save();
            }

            $this->syncOrderProductSortFromFormState();
        }

        $this->record->submitCreditInvoice();
    }

    public function submitWithEmail(array $emailData): void
    {
        $this->persistCreditInvoiceData();

        $this->record->refresh();
        $this->record->getOrCreatePublicDownloadUuid();
        $emailData = SubmitCreditInvoiceEmailAction::applyTemplateVariablesAfterPersist($this->record, $emailData);

        app(SendCreditInvoiceMailAction::class)->execute(
            invoice: $this->record,
            to: (array) ($emailData['to'] ?? []),
            cc: (array) ($emailData['cc'] ?? []),
            bcc: (array) ($emailData['bcc'] ?? []),
            subject: $emailData['subject'] ?? null,
            message: $emailData['message'] ?? null,
        );

        $this->record->setSentAt(now());
        $this->record->saveQuietly();

        if (config('exact.enabled')) {
            SyncInvoiceToExactJob::dispatch($this->record->getId(), Auth::id());
            AppSyncMessage::flashDeferredExactSyncToastPolling();
        }

        $toDisplay = is_array($emailData['to']) ? implode(', ', $emailData['to']) : $emailData['to'];
        Notification::make()
            ->title('Creditfactuur verzonden')
            ->body("E-mail is verzonden naar: {$toDisplay}")
            ->success()
            ->send();

        $this->redirect($this->getRedirectUrl());
    }

    public function syncValues(): void
    {
        $credited = 0;
        $paid = 0;
        $invoiceLines = $this->data['order_products'] ?? null;

        if (empty($invoiceLines)) {
            return;
        }

        foreach ($invoiceLines as $line) {
            $paid += (float) ($line['company_sales_price_total'] ?? 0);

            if (!empty($line['has_credit'])) {
                $credited -= abs((float) ($line['credited_amount'] ?? 0));
            }
        }

        $this->credited = $credited;
        $this->paid = $paid;

        $this->discountAmountExVat = (float) ($this->data['discount_amount_calc'] ?? 0);
        $vatFactor = 1 + ($this->vatPercentage / 100);
        $this->data['payment_amount'] = ($credited + $this->discountAmountExVat) * $vatFactor;
    }

    public function loadOrderProducts(): void
    {
        $repeater = $this->form->getComponent('order_products');
        $state = $repeater?->getState() ?? [];

        foreach ($this->record->orderProducts as $orderProduct) {
            /** @var OrderProduct $orderProduct */
            $fullAmount = abs($orderProduct->getCompanySalesPriceTotal());
            $creditedAmount = $orderProduct->getHasCredit()
                ? $orderProduct->getCompanySalesPriceCredited()
                : $orderProduct->getCompanySalesPriceTotal();

            $state["record-{$orderProduct->getId()}"] = [
                'id' => $orderProduct->getId(),
                'has_credit' => $orderProduct->getHasCredit(),
                'qty' => $orderProduct->getQty() ?? 1,
                'value' => $orderProduct->getValue(),
                'attribute_summary_basic' => is_array($orderProduct->getAttributeSummaryBasic())
                    ? implode("\n", $orderProduct->getAttributeSummaryBasic())
                    : ($orderProduct->getAttributeSummaryBasic() ?? ''),
                'company_sales_price_total' => number_format($orderProduct->getCompanySalesPriceTotal(), 2, '.', ''),
                'credited_amount' => $creditedAmount,
                'credited_amount_input' => number_format($fullAmount, 2, ',', ''),
            ];

            $this->orderProducts->put($orderProduct->getId(), $orderProduct->toArray());
        }

        $repeater?->state($state);
    }

    protected function updateCreditedAmountJs(): string
    {
        return <<<'JS'
            (() => {
                const parse = (value) => typeof value === 'string'
                    ? (parseFloat(value.replace(',', '.')) || 0)
                    : (value ?? 0);
                const val = parse($get('credited_amount_input'));
                const total = parse($get('company_sales_price_total'));
                const credited = total < 0 ? -val : val;
                $set('credited_amount', credited);
                $dispatch('update-credit-totals');
            })()
        JS;
    }

    public function form(Schema $schema): Schema
    {
        $invoice = $this->record;
        $parentInvoice = $invoice->invoice;

        $title = 'Creditfactuur';
        if ($parentInvoice) {
            $title .= ' bij #' . $parentInvoice->getUidFormatted();
        }

        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'quote-breadcrumb'])
            ->components([
                View::make('filament.resources.quote-resource.custom-styles'),
                View::make('filament.pages.edit-credit-invoice'),

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Creditfacturen',
                        'url' => route('filament.app.resources.credit-invoices.index'),
                    ]),

                Section::make($title)
                    ->columns(12)
                    ->extraAttributes(['class' => 'order-createSection'])
                    ->schema([
                        Grid::make(3)
                            ->columnSpanFull()
                            ->schema([
                                Section::make('Klant')
                                    ->extraAttributes(['class' => 'section-klantgegevens'])
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->schema([
                                        View::make('filament.resources.quote-resource.customer-data')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Factuuradres')
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->schema([
                                        View::make('filament.resources.credit-invoices.billing-address')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('')
                                    ->columns(12)
                                    ->columnSpan(1)
                                    ->heading('')
                                    ->schema([
                                        View::make('filament.resources.credit-invoices.meta-info')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        OrderProductsRepeater::make('order_products')
                            ->label('Artikelen')
                            ->default([])
                            ->addable(false)
                            ->deletable(false)
                            ->extraAttributes(['class' => 'orderProductsRepeater creditInvoiceRepeater'])
                            ->table([
                                TableColumn::make(''),
                                TableColumn::make('Aantal'),
                                TableColumn::make('Omschrijving'),
                                TableColumn::make(new HtmlString('<span>Gefactureerd</span> <span class="taxOverview">(excl. BTW)</span>')),
                                TableColumn::make(new HtmlString('<span>Te crediteren</span> <span class="taxOverview">(excl. BTW)</span>')),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('credited_amount'),

                                Checkbox::make('has_credit')
                                    ->label("\u{200B}")
                                    ->hiddenLabel()
                                    ->live()
                                    ->afterStateUpdated(fn () => $this->syncValues()),

                                TextInput::make('qty')
                                    ->label('Aantal')
                                    ->numeric()
                                    ->default(1)
                                    ->extraFieldWrapperAttributes(['class' => 'input-qty fi-disabled'])
                                    ->readOnly()
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                TextInput::make('value')
                                    ->label('Omschrijving')
                                    ->extraFieldWrapperAttributes(['class' => 'fi-disabled'])
                                    ->readOnly()
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                TextInput::make('company_sales_price_total')
                                    ->label(new HtmlString('<span>Gefactureerd</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix('€')
                                    ->numeric()
                                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, '.', ''))
                                    ->extraFieldWrapperAttributes(['class' => 'input-purchase fi-disabled'])
                                    ->readOnly()
                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                TextInput::make('credited_amount_input')
                                    ->label(new HtmlString('<span>Te crediteren</span> <span class="taxOverview">(excl. BTW)</span>'))
                                    ->prefix(fn (Get $get) => ((float) ($get('company_sales_price_total') ?? 0)) < 0 ? '€ +' : '€ -')
                                    ->live()
                                    ->afterStateUpdatedJs($this->updateCreditedAmountJs())
                                    ->disabled(fn (Get $get) => !$get('has_credit'))
                                    ->extraAttributes(['style' => 'width: 130px;'])
                                    ->extraFieldWrapperAttributes(['class' => 'input-sell']),
                            ])
                            ->columnSpanFull(),

                        Section::make('Samenvatting')
                            ->columnSpanFull()
                            ->schema([
                                View::make('filament.resources.credit-invoices.totals')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}

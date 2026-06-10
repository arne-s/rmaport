<?php

namespace App\Filament\Resources\RecurringInvoices\Pages;

use App\Enums\CustomerType;
use App\Filament\Resources\RecurringInvoices\RecurringInvoiceResource;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRecurringInvoice extends CreateRecord
{
    protected static string $resource = RecurringInvoiceResource::class;

    protected static ?string $title = 'Abonnement aanmaken';

    protected static ?string $breadcrumb = 'Abonnement aanmaken';

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
                        'title' => 'Abonnementen',
                        'url' => route('filament.app.resources.recurring-invoices.index'),
                    ]),

                Section::make('Nieuw abonnement')
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

                                        $this->createRecurringAndRedirect((int) $state);
                                    }),
                            ]),
                    ]),
            ]);
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

    public function createRecurringAndRedirect(int $billingCustomerId): void
    {
        $recurring = RecurringInvoice::createDraft($billingCustomerId);

        $this->redirect(EditRecurringInvoice::getUrl(['record' => $recurring->getKey()]));
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Doorgaan')
            ->hidden();
    }

    protected function getRedirectUrl(): string
    {
        return EditRecurringInvoice::getUrl(['record' => $this->record]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $relationId = $data['relation_id'] ?? null;
        if (! is_numeric($relationId) || (int) $relationId < 1) {
            throw ValidationException::withMessages([
                'relation_id' => 'Selecteer een klant of dealer.',
            ]);
        }

        return RecurringInvoice::createDraft((int) $relationId);
    }
}

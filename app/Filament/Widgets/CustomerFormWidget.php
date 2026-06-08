<?php

namespace App\Filament\Widgets;

use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use App\Filament\Resources\CustomerResource\Widgets\CompanyDocumentsWidget;
use App\Models\Customer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;

class CustomerFormWidget extends Widget implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected string $view = 'filament.widgets.customer-form';

    public ?array $data = [];
    public ?Customer $customer = null;
    public ?Customer $record = null;

    public function mount(): void
    {
        $this->getCustomer();
        $this->data = $this->customer->toArray();
        $this->form->fill($this->customer->toArray());
    }

    public static function canView(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->extraAttributes(['class' => 'companySection-wrapper eindklantenbacktooverview'])
            ->components([

                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Klant-overzicht',
                        'url' => route('filament.app.resources.customers.index'),
                    ]),

                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make('Klantgegevens')
                            ->schema([
                                Group::make()->schema([
                                    Grid::make(13)
                                        ->schema([
                                            Grid::make(1)
                                                ->columnSpan(6)
                                                ->schema([
                                                    Section::make('Persoonlijke gegevens')
                                                        ->extraAttributes(['class' => 'companySection eindklantenSection'])
                                                        ->disabled()
                                                        ->schema([
                                                            TextInput::make('email')
                                                                ->label(__('filament::login.fields.email.label'))
                                                                ->email()
                                                                ->required()
                                                                ->inlineLabel()
                                                                ->columnSpan(4)
                                                                ->autocomplete(),

                                                            Select::make('salutation')
                                                                ->label('Aanhef')
                                                                ->placeholder('Selecteer')
                                                                ->required()
                                                                ->inlineLabel()
                                                                ->options([
                                                                    'Dhr.' => 'Dhr.',
                                                                    'Mevr.' => 'Mevr.'
                                                                ])
                                                                ->columnSpan(4),

                                                            TextInput::make('first_name')
                                                                ->label('Voornaam')
                                                                ->required()
                                                                ->inlineLabel()
                                                                ->columnSpan(4),

                                                            TextInput::make('middle_name')
                                                                ->label('Tusv.')
                                                                ->inlineLabel()
                                                                ->columnSpan(4),

                                                            TextInput::make('last_name')
                                                                ->label('Achternaam')
                                                                ->required()
                                                                ->inlineLabel()
                                                                ->columnSpan(4),
                                                        ]),
                                                ]),

                                                     Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([]),

                                            Grid::make(1)
                                                ->columnSpan(6)
                                                ->schema([
                                                    Section::make('Adresgegevens')
                                                        ->extraAttributes(['class' => 'companySection eindklantenSection'])
                                                        ->disabled()
                                                        ->schema([
                                                            TextInput::make('address_display')
                                                                ->label('Factuur- & leveradres')
                                                                ->inlineLabel()
                                                                ->columnSpan(12)
                                                                ->placeholder(fn () => $this->customer?->address?->getAddressTemplate() ?? '—'),
                                                        ]),
                                                ]),
                                        ]),
                                ]),
                            ]),

                        Tab::make('Documenten')
                            ->schema([

                                Livewire::make(CompanyDocumentsWidget::class)
                                    ->visibleOn('edit'),
                            ]),
                    ]),
            ])->statePath('data');
    }

    protected function getCustomer()
    {
        $src = str_contains(request()->path(), 'customers/')
            ? request()->path()
            : request()->header('referer');

        $t = explode('/customers/', $src);
        [$id] = explode('/', $t[1]);
        if ($id) {
            $this->customer = Customer::find($id);
        }
    }
}

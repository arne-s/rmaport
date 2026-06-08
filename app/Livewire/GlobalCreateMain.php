<?php

namespace App\Livewire;

use App\Enums\CreateMainMode;
use App\Filament\Actions\CreateMainAction;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Attributes\On;
use Livewire\Component;

class GlobalCreateMain extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function create_mainAction(): Action
    {
        return CreateMainAction::make('create_main');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function openCreateMain(array $arguments): void
    {
        if (! CreateMainAction::canCreate()) {
            return;
        }

        $this->mountAction('create_main', $arguments);
    }

    #[On('open-create-main')]
    public function openCreateMainModal(): void
    {
        $this->openCreateMain([
            'mode' => CreateMainMode::Fitting->value,
            'from_dashboard_passing' => false,
        ]);
    }

    #[On('open-create-main-dashboard-passing')]
    public function openCreateMainFromDashboardPassingLink(): void
    {
        $this->openCreateMain([
            'mode' => CreateMainMode::Fitting->value,
            'from_dashboard_passing' => true,
        ]);
    }

    #[On('open-create-main-dashboard-quote')]
    public function openCreateMainDashboardQuoteModal(): void
    {
        $this->openCreateMain([
            'mode' => CreateMainMode::Quote->value,
            'from_dashboard_quick_links' => true,
        ]);
    }

    #[On('open-create-main-dashboard-order')]
    public function openCreateMainDashboardOrderModal(): void
    {
        $this->openCreateMain([
            'mode' => CreateMainMode::Order->value,
            'from_dashboard_quick_links' => true,
        ]);
    }

    #[On('open-create-main-quote')]
    public function openCreateMainQuoteModal(): void
    {
        $this->openCreateMain([
            'mode' => CreateMainMode::Quote->value,
            'from_dashboard_quick_links' => true,
        ]);
    }

    #[On('open-create-main-order')]
    public function openCreateMainOrderModal(): void
    {
        $this->openCreateMain([
            'mode' => CreateMainMode::Order->value,
            'from_dashboard_quick_links' => true,
        ]);
    }

    public function render()
    {
        return view('livewire.global-create-main');
    }
}

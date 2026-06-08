<?php

namespace App\Filament\Pages;

use App\Filament\Actions\CreateNoteAction;
use App\Filament\Widgets\Dashboard\ExactOnlineWidget;
use App\Filament\Widgets\Dashboard\SupportWidget;
use App\Filament\Widgets\Dashboard\AccountWidget;
use App\Filament\Widgets\Dashboard\ProductionOverviewWidget;
use App\Filament\Widgets\Dashboard\QuickLinksWidget;
use App\Filament\Widgets\NotesWidget;
use Closure;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;

class Dashboard extends Page
{
    protected string $view = 'filament.pages.dashboard';

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? static::$title ?? __('filament::pages/dashboard.title');
    }

    public function getBreadcrumbs(): array
    {
        return [
            url()->current() => 'Dashboard',
        ];
    }

    public static function getRoutes(): Closure
    {
        return function () {
            Route::get('/', static::class)->name(static::getSlug());
        };
    }

    public function getTitle(): string
    {
        return static::$title ?? __('filament::pages/dashboard.title');
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AccountWidget::class,
            SupportWidget::class,
            ExactOnlineWidget::class,
        ];
    }

    protected function getColumns(): int|string|array
    {
        return [
            'default' => 1,
            'xl' => 2,
        ];
    }

    public function getWidgets(): array
    {
        return [
            QuickLinksWidget::class,


            ProductionOverviewWidget::class,
            NotesWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 2;
    }

    protected function getFooterWidgets(): array
    {
        return [
          //  MonthyOrdersGraphWidget::class,
        ];
    }

    public function create_noteAction(): CreateNoteAction
    {
        return CreateNoteAction::make();
    }

    #[On('open-create-note')]
    public function openCreateNoteModal(): void
    {
        $this->mountAction('create_note');
    }
}

<?php

namespace App\Filament\Widgets\Dashboard;

use App\Filament\Actions\CreateMainAction;
use App\Filament\Resources\CustomerResource;
use App\Filament\Support\SalesAuthorization;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class QuickLinksWidget extends BaseWidget implements HasActions
{
    use InteractsWithActions;

    protected int | string | array $columnSpan = 'full';
    // fill width layout
    protected static string $layout = 'fill';
    protected string $view = 'filament.widgets.dashboard.quick-links-widget';

    public function create_mainAction(): CreateMainAction
    {
        return CreateMainAction::make();
    }

    public static function canView(): bool
    {
        return SalesAuthorization::canManage() && self::hasAnyQuickLinks();
    }

    public static function hasAnyQuickLinks(): bool
    {
        return (new static)->getLinks() !== [];
    }

    public function getLinks(): array
    {
        $links = [];

        if (CustomerResource::canCreate()) {
            $links[] = Action::make('klant')
                ->label('Klant')
                ->button()
                ->color('black')
                ->icon('heroicon-s-plus-circle')
                ->url(route('filament.app.resources.customers.create'));
        }

        $links = array_merge($links, [
            ...(CreateMainAction::canCreate() ? [
                Action::make('Passing')
                    ->button()
                    ->color('black')
                    ->icon('heroicon-s-plus-circle')
                    ->url('#')
                    ->extraAttributes([
                        'wire:click.prevent' => "\$dispatch('open-create-main-dashboard-passing')",
                    ]),

                Action::make('Offerte')
                    ->button()
                    ->color('black')
                    ->icon('heroicon-s-plus-circle')
                    ->url('#')
                    ->extraAttributes([
                        'wire:click.prevent' => "\$dispatch('open-create-main-dashboard-quote')",
                    ]),

                Action::make('Order')
                    ->button()
                    ->color('black')
                    ->icon('heroicon-s-plus-circle')
                    ->url('#')
                    ->extraAttributes([
                        'wire:click.prevent' => "\$dispatch('open-create-main-dashboard-order')",
                    ]),
            ] : []),
        ]);

        return $links;
    }
}

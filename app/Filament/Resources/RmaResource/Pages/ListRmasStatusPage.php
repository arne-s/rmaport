<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;
use App\Services\RmaOverviewQueries;
use Filament\Actions\Action;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;

abstract class ListRmasStatusPage extends ListRmas
{
    public ?string $status = null;

    abstract protected static function filteredStatus(): RmaStatus;

    protected function getTableQuery(): Builder
    {
        return RmaOverviewQueries::forStatus(static::filteredStatus());
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        foreach (RmaStatus::overviewStatuses() as $i => $rmaStatus) {
            $slug = $rmaStatus->overviewSlug();
            $count = RmaOverviewQueries::forStatus($rmaStatus);
            $label = $rmaStatus->getLabel() ?? $rmaStatus->value;
            $nr = $i + 1;

            $actions[] = Action::make('btn' . $nr)
                ->url(
                    request()->routeIs('filament.app.resources.rmas.' . $slug)
                        ? route('filament.app.resources.rmas.index')
                        : route('filament.app.resources.rmas.' . $slug)
                )
                ->extraAttributes(fn (): array => [
                    'class' => $this->status === $slug ? 'tab-button-blue' : 'tab-button-white',
                ])
                ->label(fn (): string => $nr . '. ' . $label . ' (' . $count->clone()->count() . ')');
        }

        return $actions;
    }

    public function getHeader(): ?ViewContract
    {
        return view('filament.resources.rmas.pages.status-overview-header', [
            'actions' => $this->getCachedHeaderActions(),
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
        ]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            route('filament.app.resources.rmas.index') => 'RMA\'s',
            static::filteredStatus()->getLabel() ?? static::filteredStatus()->value,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return parent::content($schema)
            ->components([
                View::make('filament.components.back-to-overview')
                    ->viewData([
                        'title' => 'Dashboard',
                        'url' => route('filament.app.pages.dashboard'),
                        'class' => 'mt-[-67px] breadcrumb-mob-production',
                    ]),

                ...$schema->getComponents(),
            ]);
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->filters([])
            ->header(null);
    }
}

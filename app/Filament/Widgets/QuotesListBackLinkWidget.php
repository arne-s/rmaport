<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * Back link above the quotes list; lives in header widgets so the default Filament page header
 * (breadcrumbs teleported to the topbar logo area) is still rendered.
 */
class QuotesListBackLinkWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.quotes-list-back-link';

    protected int | string | array $columnSpan = 'full';
}

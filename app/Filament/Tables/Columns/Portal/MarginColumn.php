<?php

namespace App\Filament\Tables\Columns\Portal;

use App\Models\Order\BaseOrder;
use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

/** @property BaseOrder $record */
class MarginColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.portal.margin-column';
}

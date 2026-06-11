<?php

namespace App\Filament\Forms\Components;

use Detection\MobileDetect;
use Filament\Tables\Table;

class TableReorderable extends Table
{
    protected string $view = 'filament-tables::index';

    private function shouldShowMoveButtons(): bool
    {
        /** @var MobileDetect $browser */
        $browser = app('MobileDetect');
        return
            $browser->isMobile() ||
            $browser->isTablet() ||
            // Workaround to detect iPads. Starting iPadOS 17, the user agent string is the same as macOS.
            (isset($_COOKIE['isTouchDevice']) && filter_var($_COOKIE['isTouchDevice'], FILTER_VALIDATE_BOOLEAN));
    }

    public function isReorderable(): bool
    {
        return !$this->shouldShowMoveButtons();
    }

    public function isReordering(): bool
    {
        return !$this->shouldShowMoveButtons();
    }

    public function isPaginationEnabled(): bool
    {
        return false;
    }

    public function isPaginationEnabledWhileReordering(): bool
    {
        return false;
    }
}
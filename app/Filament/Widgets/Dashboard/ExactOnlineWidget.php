<?php

namespace App\Filament\Widgets\Dashboard;

use App\Services\ExactOnlineService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class ExactOnlineWidget extends BaseWidget
{
    protected string|int|array $columnSpan = 'none';
    protected string $view = 'filament.widgets.dashboard.exact-online-widget';
    private ExactOnlineService $exactOnlineService;

    public function __construct($id = null)
    {
        $this->exactOnlineService = new ExactOnlineService();

        // parent::__construct($id);
    }

    public function isConnected(): bool
    {
        return $this->exactOnlineService->isConnected(true);
    }

}

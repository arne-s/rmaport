<?php

namespace App\Filament\Notifications;

use App\View\Components\PanelNotification;
use Filament\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Illuminate\Notifications\DatabaseNotification;
use Override;

class DatabaseNotifications extends BaseDatabaseNotifications
{
    /**
     * https://github.com/filamentphp/filament/blob/02a639c4e453762da2df37d7ef94350610c74628/packages/notifications/src/Livewire/DatabaseNotifications.php#L171
     */
    #[Override]
    public function getNotification(DatabaseNotification $notification): PanelNotification
    {
        return PanelNotification::fromDatabase($notification)
            ->date($this->formatNotificationDate($notification->getAttributeValue('created_at')));
    }
}

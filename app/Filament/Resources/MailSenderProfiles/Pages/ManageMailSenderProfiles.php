<?php

namespace App\Filament\Resources\MailSenderProfiles\Pages;

use App\Filament\Resources\MailSenderProfiles\MailSenderProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMailSenderProfiles extends ManageRecords
{
    protected static string $resource = MailSenderProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

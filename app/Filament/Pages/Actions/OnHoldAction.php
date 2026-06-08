<?php

namespace App\Filament\Pages\Actions;

use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithRecord;

class OnHoldAction extends Action
{
    use InteractsWithRecord;

    public static function getDefaultName(): ?string
    {
        return 'on_hold';
    }

    public function getExtraAttributes(): array
    {
        return [
            'class' => 'bg-white',
        ];
    }

    public function setRecordData(Customer $record): static
    {
        $this->record = $record;
        $this->button()->label($record->getIsActive()
            ? 'Op on-hold zetten'
            : 'Op actief zetten'
        );

        $this->color($record->getIsActive() ? 'secondary' : 'primary');

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->groupedIcon('heroicon-m-arrow-uturn-left');

        $this->requiresConfirmation();

        $this->action(function (Customer $record): void {
            $record->setIsActive(!$record->getIsActive());

            if (!$record->save()) {
                $this->failure();

                return;
            }

            $this->success();
        });
    }
}

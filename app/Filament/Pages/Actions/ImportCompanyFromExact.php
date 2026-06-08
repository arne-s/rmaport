<?php

namespace App\Filament\Pages\Actions;

use App\Models\Customer;
use App\Services\ExactOnlineService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Actions\Concerns\InteractsWithRecord;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Component;

// Unused
class ImportCompanyFromExact extends Action
{
    use InteractsWithRecord;

    public static function getDefaultName(): ?string
    {
        return 'import_company';
    }

    public function getModalDescription(): string|Htmlable|null
    {
        return 'Gebruik het veld "code" (4 cijfers)';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->button()->label('Gebruiker importeren uit Exact');

        $this->groupedIcon('heroicon-m-arrow-up-circle');

        $this->requiresConfirmation();

        $this->action(function (array $data, Component $livewire): void {
            $this->icon('heroicon-s-cog');
            $service = new ExactOnlineService();
            try {
                $data = $service->getCustomerData($data['code']);
            } catch (Exception $e) {
                Notification::make()
                    ->title('Exact is niet bereikbaar')
                    ->danger()
                    ->send();

                $this->failure();

                return;
            }

            if (!$data) {
                Notification::make()
                    ->title('Gebruiker is niet gevonden in Exact')
                    ->danger()
                    ->send();

                $this->failure();

                return;
            }

            if (Customer::where('exact_id', $data['ID'])->orWhere('debtor_number', $data['Code'])->exists()) {
                Notification::make()
                    ->title('Gebruiker bestaat al')
                    ->danger()
                    ->send();

                $this->failure();

                return;
            }

            Notification::make()
                ->title('Gebruiker is gevonden in Exact')
                ->success()
                ->send();

            $livewire->dispatch('companyDataFetched', $data);

            $this->success();
        });

        $this->schema([
            TextInput::make('code')
                ->label('Code')
                ->maxLength(5)
                ->required(),
        ]);
    }
}

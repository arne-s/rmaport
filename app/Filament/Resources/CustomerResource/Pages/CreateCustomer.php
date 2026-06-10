<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * @property Customer $record
 */
class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;
    protected static bool $canCreateAnother = false;
    protected static ?string $breadcrumb = 'Klant aanmaken';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([
                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Klanten-overzicht',
                        'url' => route('filament.app.resources.customers.index'),
                    ]),

                Section::make()
                    ->columns(4)
                    ->schema([
                        Select::make('type')
                            ->label('Type')
                            ->options(CustomerType::visibleLabelsForCreate())
                            ->columnSpan(1)
                            ->required(),
                    ]),
            ]);
    }

    public function getHeading(): string
    {
        return 'Nieuwe klant aanmaken';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = CustomerStatus::Initial->value;

        $typeKey = static::normalizedCustomerTypeKeyForDefaults($data['type'] ?? null);
        if ($typeKey !== null) {
            $conditionCode = Setting::get('exact_payment_condition_by_type.'.$typeKey);
            if (is_string($conditionCode) && $conditionCode !== '') {
                $data['exact_payment_condition'] = $conditionCode;
            }
        }

        return $data;
    }

    /**
     * In {@see mutateFormDataBeforeCreate} the type may be a string or {@see CustomerType} depending on Filament / cast.
     */
    private static function normalizedCustomerTypeKeyForDefaults(mixed $type): ?string
    {
        return match (true) {
            $type instanceof CustomerType => $type->value,
            $type instanceof \BackedEnum => (string) $type->value,
            is_array($type) && isset($type['value']) && is_string($type['value']) && $type['value'] !== '' => $type['value'],
            is_string($type) && trim($type) !== '' => trim($type),
            default => null,
        };
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('edit', ['record' => $this->record]);
    }

    /**
     * New customers start as draft ({@see CustomerStatus::Initial}); suppress the default "created" toast until the final flow.
     */
    protected function getCreatedNotificationTitle(): ?string
    {
        return null;
    }

    protected function getCreateFormAction(): Action
    {
        return Action::make('create')
            ->label('Aanmaken')
            ->submit('create')
            ->keyBindings(['mod+s']);
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Klant toevoegen';
    }
}

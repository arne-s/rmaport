<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\DispatchesExactSyncToastPolling;
use App\Filament\Resources\ProductResource;
use App\Jobs\SyncProductToExactJob;
use App\Models\ExactVATCode;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use DispatchesExactSyncToastPolling;

    protected static string $resource = ProductResource::class;
    public bool $isBundle = false;
    public bool $isGroup = false;

    protected static ?string $title = 'Artikel';

    protected static ?string $breadcrumb = 'Artikel aanmaken';


    protected function afterCreate(): void
    {
        /** @var Product $product */
        $product = $this->record;

        if (! config('exact.enabled')) {
            Notification::make()
                ->title('Exact-koppeling uitgeschakeld')
                ->warning()
                ->send();

            return;
        }

        if (! $product->shouldBeSyncedToExact() || ! $product->exact_article_group_id) {
            return;
        }

        SyncProductToExactJob::dispatch($product->id, auth()->id());
        $this->requestExactSyncToastPollingAfterRedirect();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    protected function afterFill(): void
    {
        // Prefill some form values so we can show and hide elements in the form based on these
        $this->data['is_visible_portal'] = false;
        $this->data['is_bundle_parent'] = $this->isBundle;
        $this->data['is_group_product'] = $this->isGroup;
        $this->data['is_individually_visible'] = !$this->isBundle;

        if (empty($this->data['exact_sales_vat_code_id'])) {
            $this->data['exact_sales_vat_code_id'] = ExactVATCode::query()
                ->where('code', ExactVATCode::DEFAULT_SALES_VAT_CODE)
                ->whereIn('vat_transaction_type', [
                    ExactVATCode::VAT_TRANSACTION_TYPES['sales'],
                    ExactVATCode::VAT_TRANSACTION_TYPES['both'],
                ])
                ->where('is_blocked', false)
                ->value('id');
        }
    }

    public static bool $canCreateAnother = false;


    public function getFormActions(): array
    {
        return [
            Action::make('save_draft')
                ->label('Opslaan')
                ->action('create')
                ->extraAttributes(['class' => 'hideSaveButton']),
            Action::make('cancel')
                ->label('Annuleren')
                ->extraAttributes(['class' => 'white'])
                ->url(fn () => ProductResource::getUrl())
                ->color('gray'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Actions\EditAction::make('save_draft')
            //     ->label('Opslaan')
            //     ->action('create')
            //     ->extraAttributes(['class' => 'hideSaveButton']),
            // Actions\Action::make('cancel')
            //     ->label('Annuleren')
            //     ->extraAttributes(['class' => 'white'])
            //     ->url(fn () => ProductResource::getUrl())
            //     ->color('secondary'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Nieuw artikel aangemaakt';
    }

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()
            ->label('Opslaan');
    }

    public function getTitle(): string
    {
        return $this->isBundle ? 'Samengesteld artikel' : 'Artikel toevoegen';
    }
}

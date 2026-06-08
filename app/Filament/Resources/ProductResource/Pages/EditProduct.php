<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\DispatchesExactSyncToastPolling;
use App\Filament\Resources\ProductResource;
use App\Jobs\SyncProductToExactJob;
use App\Models\Product;
use Exception;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use DispatchesExactSyncToastPolling;

    protected static string $resource = ProductResource::class;

    protected static ?string $breadcrumb = '';

    /**
     * @throws Exception
     */
    protected function getHeaderActions(): array
    {
        return [];
    }


    protected function getFormActions(): array
    {
        return [
            Html::make('<div class="editproduct-footer-actions">'),
                Html::make('<div>'),
                Action::make('save')
                    ->label('Wijzigingen opslaan')
                    ->action('save'),
                Action::make('cancel')
                    ->label('Annuleren')
                    ->extraAttributes(['class' => 'white'])
                    ->url(fn() => ProductResource::getUrl())
                    ->color('gray'),
                Html::make('</div>'),
                Html::make('<div>'),

                DeleteAction::make('delete')
                    ->record($this->record)
                    ->requiresConfirmation()
                    ->label('Verwijderen')
                    ->hidden(fn() => $this->record->is_active)
                    ->extraAttributes(['class' => 'white color-red-delete'])
                    ->successRedirectUrl(ProductResource::getUrl()),
                Html::make('</div>'),
            Html::make('</div>'),
        ];
    }

    public function getTitle(): string
    {
        return $this->getProductHeadingName();
    }

    public function getHeading(): string
    {
        return $this->getProductHeadingTitle();
    }

    public function getProductHeadingName(): string
    {
        $name = trim((string) ($this->record?->getName() ?? ''));
        $uid = trim((string) ($this->record?->getUid() ?? ''));

        if ($name === '' && $uid === '') {
            return '-';
        }

        if ($name === '') {
            return $uid;
        }

        if ($uid === '') {
            return $name;
        }

        return "{$name} | {$uid}";
    }

    private function getProductHeadingTitle(): string
    {
        return 'Artikel: ' . $this->getProductHeadingName();
    }

    public function afterSave(): void
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
        $this->requestExactSyncToastPolling();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $comment = $data['price_change_comment'] ?? $this->data['price_change_comment'] ?? null;
        $comment = is_string($comment) ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        $actionContext = [
            '_method' => 'manual',
            '_comment' => $comment,
        ];

        $priceFields = [
            'company_purchase_price',
            'company_sales_price',
            'company_margin',
            'company_markup',
        ];

        foreach ($priceFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $newValue = (float) ($data[$field] ?? 0);
            $originalValue = (float) ($this->record->getOriginal($field) ?? $this->record->getAttribute($field) ?? 0);

            if (abs($newValue - $originalValue) > 0.0001) {
                $actionContext[$field] = '';
            }
        }

        $this->record->price_change_action_context = $actionContext;

        unset($data['price_change_comment']);

        return $data;
    }
}

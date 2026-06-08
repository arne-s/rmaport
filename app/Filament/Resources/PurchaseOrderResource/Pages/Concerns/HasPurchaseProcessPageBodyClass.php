<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages\Concerns;

trait HasPurchaseProcessPageBodyClass
{
    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        $parent = parent::getExtraBodyAttributes();
        $classes = array_filter(preg_split('/\s+/', (string) ($parent['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $classes[] = 'purchase-process-page';

        return array_merge($parent, [
            'class' => implode(' ', array_unique($classes)),
        ]);
    }
}

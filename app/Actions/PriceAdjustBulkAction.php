<?php

namespace App\Actions;

use App\Support\Pricing\ProductPricingCalculator;
use Filament\Actions\BulkAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Collection;
use Filament\Support\Enums\Width;
use App\Models\Product;

class PriceAdjustBulkAction extends BulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Prijzen wijzigen')
            ->modalHeading('Prijzen wijzigen')
            ->color('primary')
            ->icon('heroicon-s-currency-euro')
            ->modalWidth(Width::Medium)
            ->modalCancelAction(false)
            ->successNotificationTitle('Prijsaanpassing succesvol!')
            ->deselectRecordsAfterCompletion()
            ->schema([
                Select::make('target')
                    ->label('Wat wil je wijzigen?')
                    ->options([
                        'company_purchase_price' => 'Inkoop',
                        'company_sales_price' => 'Verkoop',
                        'company_purchase_and_sales_price' => 'Inkoop en Verkoop',
                        'company_margin' => 'Marge',
                        'company_markup' => 'Opslag',
                    ])
                    ->reactive()
                    ->required(),

                Select::make('adjustment_type')
                    ->label('Wil je een verhoging of verlaging?')
                    ->options([
                        'increase' => 'Verhoging',
                        'decrease' => 'Verlaging',
                    ])
                    ->hidden(fn($get) => in_array($get('target'), ['company_margin', 'company_markup'], true))
                    ->required(fn($get) => ! in_array($get('target'), ['company_margin', 'company_markup'], true)),

                TextInput::make('adjustment_percentage')
                    ->label('Percentage (%)')
                    ->numeric()
                    ->required(fn ($get): bool => in_array($get('target'), [
                        'company_purchase_price',
                        'company_sales_price',
                    ], true))
                    ->hidden(fn ($get): bool => ! in_array($get('target'), [
                        'company_purchase_price',
                        'company_sales_price',
                    ], true))
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),

                TextInput::make('purchase_adjustment_percentage')
                    ->label('Inkoop percentage (%)')
                    ->numeric()
                    ->required(fn ($get): bool => $get('target') === 'company_purchase_and_sales_price')
                    ->hidden(fn ($get): bool => $get('target') !== 'company_purchase_and_sales_price')
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),

                TextInput::make('sales_adjustment_percentage')
                    ->label('Verkoop percentage (%)')
                    ->numeric()
                    ->required(fn ($get): bool => $get('target') === 'company_purchase_and_sales_price')
                    ->hidden(fn ($get): bool => $get('target') !== 'company_purchase_and_sales_price')
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),

                TextInput::make('margin_value')
                    ->label('Marge (%)')
                    ->numeric()
                    ->required(fn ($get): bool => $get('target') === 'company_margin')
                    ->hidden(fn ($get): bool => $get('target') !== 'company_margin')
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%'),

                TextInput::make('markup_value')
                    ->label('Opslag (%)')
                    ->numeric()
                    ->required(fn ($get): bool => $get('target') === 'company_markup')
                    ->hidden(fn ($get): bool => $get('target') !== 'company_markup')
                    ->minValue(0)
                    ->maxValue(1000)
                    ->suffix('%'),

                Textarea::make('comment')
                    ->label('Opmerking')
                    ->rows(3)
                    ->maxLength(1000)
                    ->nullable(),
            ])
            ->action(function (HasTable $livewire, Collection $records, array $data): void {
                $this->adjustPrices($records, $data);
            });
    }

    /**
     * @param array $data
     */
    private function adjustPrices(Collection $records, array $data): void
    {
        foreach ($records as $record) {
            if (! $record instanceof Product) {
                continue;
            }

            $target = (string) ($data['target'] ?? '');
            $isIncrease = ($data['adjustment_type'] ?? 'increase') === 'increase';
            $currentPurchasePrice = (float) ($record->company_purchase_price ?? 0);
            $currentSalesPrice = (float) ($record->company_sales_price ?? 0);
            $currentMargin = (float) ($record->company_margin ?? 0);
            $currentMarkup = (float) ($record->company_markup ?? 0);
            $updateData = [];
            $actionContext = [
                '_method' => 'bulk',
                '_comment' => $data['comment'] ?? null,
            ];

            if ($target === 'company_purchase_price') {
                $adjustmentPercentage = ((float) ($data['adjustment_percentage'] ?? 0)) / 100;
                $newPurchasePrice = $this->applyPercentageAdjustment($currentPurchasePrice, $adjustmentPercentage, $isIncrease);
                $newSalesPrice = ProductPricingCalculator::recalculateSalesFromPurchaseAndMarkup($newPurchasePrice, $currentMarkup);
                $newMargin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($newPurchasePrice, $newSalesPrice);

                $updateData = [
                    'company_purchase_price' => $newPurchasePrice,
                    'company_sales_price' => $newSalesPrice,
                    'company_margin' => $newMargin,
                ];
                $actionContext['company_purchase_price'] = $this->buildPercentageActionLabel($isIncrease, $adjustmentPercentage);
            } elseif ($target === 'company_sales_price') {
                $adjustmentPercentage = ((float) ($data['adjustment_percentage'] ?? 0)) / 100;
                $newSalesPrice = $this->applyPercentageAdjustment($currentSalesPrice, $adjustmentPercentage, $isIncrease);
                $newMargin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($currentPurchasePrice, $newSalesPrice);
                $newMarkup = ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales($currentPurchasePrice, $newSalesPrice);

                $updateData = [
                    'company_sales_price' => $newSalesPrice,
                    'company_margin' => $newMargin,
                    'company_markup' => $newMarkup,
                ];
                $actionContext['company_sales_price'] = $this->buildPercentageActionLabel($isIncrease, $adjustmentPercentage);
            } elseif ($target === 'company_purchase_and_sales_price') {
                $purchaseAdjustmentPercentage = ((float) ($data['purchase_adjustment_percentage'] ?? 0)) / 100;
                $salesAdjustmentPercentage = ((float) ($data['sales_adjustment_percentage'] ?? 0)) / 100;
                $newPurchasePrice = $this->applyPercentageAdjustment($currentPurchasePrice, $purchaseAdjustmentPercentage, $isIncrease);
                $newSalesPrice = $this->applyPercentageAdjustment($currentSalesPrice, $salesAdjustmentPercentage, $isIncrease);
                $newMargin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($newPurchasePrice, $newSalesPrice);
                $newMarkup = ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales($newPurchasePrice, $newSalesPrice);

                $updateData = [
                    'company_purchase_price' => $newPurchasePrice,
                    'company_sales_price' => $newSalesPrice,
                    'company_margin' => $newMargin,
                    'company_markup' => $newMarkup,
                ];
                $actionContext['company_purchase_price'] = $this->buildPercentageActionLabel($isIncrease, $purchaseAdjustmentPercentage);
                $actionContext['company_sales_price'] = $this->buildPercentageActionLabel($isIncrease, $salesAdjustmentPercentage);
            } elseif ($target === 'company_margin') {
                $newMargin = round((float) ($data['margin_value'] ?? 0), 2);
                $newSalesPrice = ProductPricingCalculator::recalculateSalesFromPurchaseAndMargin($currentPurchasePrice, $newMargin);
                $newMarkup = ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales($currentPurchasePrice, $newSalesPrice);

                $updateData = [
                    'company_margin' => $newMargin,
                    'company_sales_price' => $newSalesPrice,
                    'company_markup' => $newMarkup,
                ];
                $actionContext['company_margin'] = 'set ' . $this->formatPercentage($newMargin) . '%';
            } elseif ($target === 'company_markup') {
                $newMarkup = round((float) ($data['markup_value'] ?? 0), 2);
                $newSalesPrice = ProductPricingCalculator::recalculateSalesFromPurchaseAndMarkup($currentPurchasePrice, $newMarkup);
                $newMargin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($currentPurchasePrice, $newSalesPrice);

                $updateData = [
                    'company_markup' => $newMarkup,
                    'company_sales_price' => $newSalesPrice,
                    'company_margin' => $newMargin,
                ];
                $actionContext['company_markup'] = 'set ' . $this->formatPercentage($newMarkup) . '%';
            }

            if ($updateData === []) {
                continue;
            }

            $record->price_change_action_context = $actionContext;
            $record->update($updateData);
        }
    }

    private function applyPercentageAdjustment(float $currentValue, float $adjustmentPercentage, bool $isIncrease): float
    {
        $multiplier = $isIncrease ? (1 + $adjustmentPercentage) : (1 - $adjustmentPercentage);

        return round($currentValue * $multiplier, 2);
    }

    private function buildPercentageActionLabel(bool $isIncrease, float $adjustmentPercentage): string
    {
        return ($isIncrease ? '+' : '-') . $this->formatPercentage($adjustmentPercentage * 100) . '%';
    }

    private function formatPercentage(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

}

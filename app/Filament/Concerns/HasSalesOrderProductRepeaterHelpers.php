<?php

namespace App\Filament\Concerns;

use App\Filament\Forms\Components\ProductSelect;
use App\Filament\Support\OrderProductRepeaterConfiguration;
use App\Models\OrderProduct;
use App\Models\Product;
use App\Models\RecurringInvoiceLine;
use Closure;
use Illuminate\Support\Carbon;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

trait HasSalesOrderProductRepeaterHelpers
{
    protected bool $isResyncingOrderProductsAfterSave = false;

    protected function configureOrderProductsRepeaterSorting(Repeater $repeater): Repeater
    {
        return OrderProductRepeaterConfiguration::apply($repeater);
    }

    protected function configureOrderRepeaterProductSelect(ProductSelect $select): ProductSelect
    {
        return $select->anchorProductId(
            fn (Get $get): ?int => filled($get('product_id')) ? (int) $get('product_id') : null,
        );
    }

    protected function rejectNonPurchaseProductForPurchaseRepeater(Product $product, Set $set): bool
    {
        if ($product->is_purchase_item) {
            return false;
        }

        Notification::make()
            ->title('Artikel niet beschikbaar voor inkoop')
            ->body('Dit artikel is niet gemarkeerd als inkoopartikel.')
            ->warning()
            ->send();

        $set('product_id', null);

        return true;
    }

    protected function syncOrderProductSortFromFormState(): void
    {
        $rows = $this->data['order_products'] ?? [];

        if (! is_array($rows)) {
            return;
        }

        $sort = 1;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            OrderProduct::query()->whereKey($id)->update(['sort' => $sort]);
            $sort++;
        }
    }

    protected function syncRecurringInvoiceLineSortFromFormState(): void
    {
        $rows = $this->data['order_products'] ?? [];

        if (! is_array($rows)) {
            return;
        }

        $sort = 1;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            RecurringInvoiceLine::query()->whereKey($id)->update(['sort' => $sort]);
            $sort++;
        }
    }

    /**
     * Re-baseline Filament unsaved-changes detection after custom save logic that runs after parent::save().
     */
    protected function syncUnsavedChangesAlertAfterCustomSave(): void
    {
        if (! method_exists($this, 'rememberData')) {
            return;
        }

        if (isset($this->record)) {
            $this->record->refresh();
        }

        $this->resyncOrderProductsStateFromRecordAfterSave();

        if (method_exists($this, 'hydrateAddressFormFromRecord')) {
            $this->hydrateAddressFormFromRecord();
        }

        if (method_exists($this, 'formatCompanyPurchasePriceDiscount')) {
            $this->formatCompanyPurchasePriceDiscount();
        }

        if (method_exists($this, 'formatCompanySalesPriceDiscount')) {
            $this->formatCompanySalesPriceDiscount();
        }

        $this->baselineFormStateForUnsavedChangesAlert();

        $this->rememberData();
    }

    protected function baselineFormStateForUnsavedChangesAlert(): void
    {
        if (! isset($this->form) || ! is_array($this->data)) {
            return;
        }

        // Use $this->data — not form->getState() — so non-dehydrated and disabled fields
        // (e.g. customer_id, main_reference_sync) are not stripped before rememberData().
        $this->form->fill($this->normalizeFormStateForUnsavedChangesAlert($this->data));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function normalizeFormStateForUnsavedChangesAlert(array $state): array
    {
        if (array_key_exists('created_at', $state) && filled($state['created_at'])) {
            $state['created_at'] = Carbon::parse($state['created_at'])->toDateString();
        }

        return $state;
    }

    /**
     * Reload order product repeater state from the database so savedDataHash matches persisted rows
     * (e.g. after VAT or purchase-price updates that run outside parent::save()).
     */
    protected function resyncOrderProductsStateFromRecordAfterSave(): void
    {
        if (! property_exists($this, 'orderProducts') || ! method_exists($this, 'loadOrderProducts')) {
            return;
        }

        if (! isset($this->record, $this->form)) {
            return;
        }

        $this->orderProducts = collect();

        if (property_exists($this, 'orderProductsToDelete')) {
            $this->orderProductsToDelete = [];
        }

        $this->record->loadMissing(['orderProducts.product']);

        try {
            $this->form->getComponent('order_products')->state([]);
        } catch (\Throwable) {
            return;
        }

        $this->isResyncingOrderProductsAfterSave = true;

        try {
            $this->loadOrderProducts();
        } finally {
            $this->isResyncingOrderProductsAfterSave = false;
        }
    }

    protected function formatLineItemAmount(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, ',', '.');
    }

    protected function orderProductAllowsFractionalQtyHiddenField(): Hidden
    {
        return Hidden::make('product_allows_fractional_qty')
            ->dehydrated(false);
    }

    protected function orderProductRowAllowsFractionalQty(Get $get): bool
    {
        $flag = $get('product_allows_fractional_qty');

        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $flag;
        }

        return $this->productAllowsFractionalQty((int) ($get('product_id') ?? 0));
    }

    protected function productAllowsFractionalQty(?int $productId): bool
    {
        if ($productId === null || $productId <= 0) {
            return true;
        }

        $product = Product::query()->find($productId);

        return (bool) ($product?->is_fraction_allowed_item);
    }

    protected function isQtyInputIncomplete(mixed $state): bool
    {
        if (! is_string($state)) {
            return false;
        }

        return (bool) preg_match('/[.,]\s*$/', trim($state));
    }

    protected function normalizeQtyForProduct(?int $productId, mixed $qty): float
    {
        $parsed = $this->parseLineItemAmount($qty);

        if ($parsed <= 0) {
            $parsed = 1;
        }

        if ($this->productAllowsFractionalQty($productId)) {
            return round($parsed, 2);
        }

        return (float) max(1, (int) round($parsed));
    }

    protected function qtyForOrderProductRepeaterRow(OrderProduct $orderProduct): float
    {
        return $this->normalizeQtyForProduct(
            $orderProduct->getProductId(),
            $orderProduct->getQty() ?? 1,
        );
    }

    protected function productAllowsFractionalQtyForOrderProductRow(OrderProduct $orderProduct): bool
    {
        $orderProduct->loadMissing('product');

        return (bool) $orderProduct->product?->is_fraction_allowed_item;
    }

    protected function syncOrderProductRepeaterProductFlags(Set $set, Product $product): void
    {
        $set('product_allows_fractional_qty', $product->is_fraction_allowed_item);
    }

    /**
     * @param  (callable(Get): void)|null  $afterQtyUpdated
     */
    protected function configureOrderProductQtyField(
        TextInput $field,
        ?callable $afterQtyUpdated = null,
        bool $disabled = false,
    ): TextInput {
        $field = $field
            ->label('Aantal')
            ->extraFieldWrapperAttributes(['class' => 'input-qty'])
            ->numeric()
            ->default(1)
            ->live()
            ->afterStateUpdatedJs($this->updatePricesJs())
            ->required();

        if ($disabled) {
            $field->disabled();
        }

        $field
            ->minValue(fn (Get $get): float => $this->orderProductRowAllowsFractionalQty($get) ? 0.01 : 1)
            ->step(fn (Get $get): float|int => $this->orderProductRowAllowsFractionalQty($get) ? 0.01 : 1)
            ->inputMode(fn (Get $get): string => $this->orderProductRowAllowsFractionalQty($get) ? 'decimal' : 'numeric')
            ->extraInputAttributes(fn (Get $get): array => [
                'step' => $this->orderProductRowAllowsFractionalQty($get) ? '0.01' : '1',
                'min' => $this->orderProductRowAllowsFractionalQty($get) ? '0.01' : '1',
                'inputmode' => $this->orderProductRowAllowsFractionalQty($get) ? 'decimal' : 'numeric',
            ])
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    $productId = (int) ($get('product_id') ?? 0);

                    if ($productId <= 0 || $this->orderProductRowAllowsFractionalQty($get)) {
                        return;
                    }

                    $parsed = $this->parseLineItemAmount($value);

                    if ($parsed <= 0) {
                        return;
                    }

                    if (abs($parsed - round($parsed)) > 0.00001) {
                        $fail('Dit artikel is niet deelbaar; voer een geheel aantal in.');
                    }
                },
            ])
            ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($afterQtyUpdated): void {
                if ($this->isQtyInputIncomplete($state)) {
                    if ($afterQtyUpdated !== null) {
                        $afterQtyUpdated($get);
                    }

                    return;
                }

                $productId = (int) ($get('product_id') ?? 0);

                if ($productId > 0) {
                    $normalized = $this->normalizeQtyForProduct($productId, $state);

                    if ($this->parseLineItemAmount($state) !== $normalized) {
                        $set('qty', $normalized);
                    }
                }

                if ($afterQtyUpdated !== null) {
                    $afterQtyUpdated($get);
                }
            });

        return $field;
    }

    protected function syncOrderProductQtyForSelectedProduct(Get $get, Set $set, int $productId): void
    {
        $product = Product::query()->find($productId);

        if ($product instanceof Product) {
            $this->syncOrderProductRepeaterProductFlags($set, $product);
        }

        $set('qty', $this->normalizeQtyForProduct($productId, $get('qty') ?? 1));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function applyOrderProductSpecificationsFromFormRow(OrderProduct $op, array $row): void
    {
        if (! array_key_exists('attribute_summary_basic', $row)) {
            return;
        }

        $op->setAttributeSummaryBasic(
            $this->normalizeAttributeSummaryBasicForSave($row['attribute_summary_basic'] ?? null),
        );
    }

    protected function normalizeAttributeSummaryBasicForSave(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $value = arrayToTextareaString($value);
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function parseLineItemAmount(float|int|string|null $value): float
    {
        if (! is_string($value)) {
            return (float) ($value ?? 0);
        }

        $value = trim($value);

        if ($value === '') {
            return 0.0;
        }

        $value = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

        if (str_contains($value, ',')) {
            return (float) str_replace(['.', ','], ['', '.'], $value);
        }

        if (preg_match('/^\d+\.\d+$/', $value) === 1) {
            return (float) $value;
        }

        return (float) str_replace('.', '', $value);
    }

    protected function salesPriceBaseForInput(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->formatLineItemAmount($value);
    }

    protected function salesPriceBaseForDatabase(float|int|string|null $value): float
    {
        return round($this->parseLineItemAmount($value), 2);
    }

    protected function companySalesPriceBaseRepeaterField(): TextInput
    {
        return TextInput::make('company_sales_price_base')
            ->label(new HtmlString('<span>Verkoop</span> <span class="taxOverview">(excl. BTW)</span>'))
            ->prefix('€')
            ->live(onBlur: true)
            ->belowContent(
                Text::make($this->calculateCompanyMarginBaseJs())
                    ->js()
                    ->extraAttributes(['style' => 'white-space: pre-line'])
            )
            ->afterStateUpdatedJs($this->updatePricesJs())
            ->extraFieldWrapperAttributes(['class' => 'input-sell']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function applyOrderProductSalesPricingFromFormRow(OrderProduct $op, array $row): void
    {
        $qty = $this->normalizeQtyForProduct($op->getProductId(), $row['qty'] ?? 1);

        $base = $this->parseLineItemAmount(
            $row['company_sales_price_base'] ?? $op->getCompanySalesPriceBase()
        );
        $discountPct = (float) str_replace(',', '.', (string) ($row['company_sales_price_discount_percentage'] ?? 0));
        $purchaseBase = (float) $op->getCompanyPurchasePriceBase();
        $lineSalesSubtotal = $base * $qty;
        $salesDiscountAmount = $lineSalesSubtotal * ($discountPct / 100);
        $salesTotal = $lineSalesSubtotal - $salesDiscountAmount;
        $purchaseLineTotal = $purchaseBase * $qty;

        $op->setQty($qty);
        $op->setCompanySalesPriceBase($base);
        $op->setCompanySalesPriceDiscountPercentage($discountPct);
        $op->setAttributeSummaryBasic($this->normalizeAttributeSummaryBasicForSave($row['attribute_summary_basic'] ?? null));
        $op->company_sales_price_subtotal = round($lineSalesSubtotal, 2);
        $op->company_sales_price_discount = round($salesDiscountAmount, 2);
        $op->company_sales_price_total = round($salesTotal, 2);
        $op->company_purchase_price_subtotal = round($purchaseBase, 2);
        $op->company_purchase_price_total = round($purchaseLineTotal, 2);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function applyRecurringLineSalesPricingFromFormRow(RecurringInvoiceLine $line, array $row): void
    {
        $productId = isset($row['product_id']) ? (int) $row['product_id'] : null;
        $line->qty = (string) $this->normalizeQtyForProduct($productId, $row['qty'] ?? 1);
        $line->company_sales_price_base = (string) $this->parseLineItemAmount(
            $row['company_sales_price_base'] ?? $line->company_sales_price_base
        );
        $line->company_sales_price_discount_percentage = (string) ((float) str_replace(
            ',',
            '.',
            (string) ($row['company_sales_price_discount_percentage'] ?? 0)
        ));
        $line->attribute_summary_basic = $this->normalizeAttributeSummaryBasicForSave($row['attribute_summary_basic'] ?? null);
    }

    protected function repeaterLocaleParseJs(): string
    {
        return <<<'JS'
            const parseAmount = (value) => {
                if (typeof value !== 'string') {
                    return Number(value ?? 0) || 0;
                }
                const normalized = value.trim().replace(/\s/g, '');
                if (normalized.includes(',')) {
                    return parseFloat(normalized.replace(/\./g, '').replace(',', '.')) || 0;
                }
                return parseFloat(normalized) || 0;
            };
            const parsePercentage = (value) => {
                if (typeof value !== 'string') {
                    return Number(value ?? 0) || 0;
                }
                const normalized = value.trim().replace(/%/g, '').replace(/\s/g, '');
                if (normalized.includes(',')) {
                    return parseFloat(normalized.replace(/\./g, '').replace(',', '.')) || 0;
                }
                return parseFloat(normalized) || 0;
            };
            JS;
    }

    protected function calculateCompanyMarginBaseJs(): string
    {
        return $this->repeaterLocaleParseJs().<<<'JS'

            (() => {
                const companyPurchasePriceBase = parseAmount($get('company_purchase_price_base'));
                const companySalesPriceBase = parseAmount($get('company_sales_price_base'));
                if (companyPurchasePriceBase <= 0)
                    return '';

                const marginAmount = companySalesPriceBase - companyPurchasePriceBase;
                const amountFormatted = marginAmount.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                const marginPercentage = ((companySalesPriceBase / companyPurchasePriceBase) - 1) * 100;
                const percentageFormatted = marginPercentage.toLocaleString('nl-NL', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

                return `Opslag: €${amountFormatted}\n(${percentageFormatted}%)`;
            })()
            JS;
    }

    protected function calculateCompanyMarginTotalJs(): string
    {
        return $this->repeaterLocaleParseJs().<<<'JS'

            (() => {
                const companyPurchasePriceBase = parseAmount($get('company_purchase_price_base'));
                const companySalesPriceBase = parseAmount($get('company_sales_price_base'));
                const qty = parseAmount($get('qty')) || 1;
                const companyPurchasePriceTotal = companyPurchasePriceBase * qty;
                if (companyPurchasePriceTotal <= 0)
                    return '';

                const marginAmount = (companySalesPriceBase * qty) - companyPurchasePriceTotal;
                const amountFormatted = marginAmount.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                const marginPercentage = ((companySalesPriceBase / companyPurchasePriceBase) - 1) * 100;
                const percentageFormatted = marginPercentage.toLocaleString('nl-NL', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

                return `Opslag: €${amountFormatted}\n(${percentageFormatted}%)`;
            })()
            JS;
    }

    protected function updatePricesJs(): string
    {
        return $this->repeaterLocaleParseJs().<<<'JS'

            (() => {
                const format = (value) => parseFloat(value.toPrecision(12)).toLocaleString('nl-NL', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
                const companyPurchasePriceBase = parseAmount($get('company_purchase_price_base'));
                const companySalesPriceBase = parseAmount($get('company_sales_price_base'));
                const discountPct = parsePercentage($get('company_sales_price_discount_percentage'));

                const qty = parseAmount($get('qty')) || 1;
                const subtotalBeforeDiscount = companySalesPriceBase * qty;
                const discount = subtotalBeforeDiscount * (discountPct / 100);
                const companySalesPriceTotal = subtotalBeforeDiscount - discount;

                const companySalesPriceQtyTotal = companySalesPriceBase * qty;

                $set('company_purchase_price_base', format(companyPurchasePriceBase));
                $set('company_sales_price_qty_total', format(companySalesPriceQtyTotal));
                $set('company_sales_price_total', format(companySalesPriceTotal));

                $dispatch('update-totals');
            })()
            JS;
    }
}

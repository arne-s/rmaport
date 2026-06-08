<?php

namespace App\Filament\Imports;

use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Models\ExactArticleGroup;
use App\Models\ExactVATCode;
use App\Models\Product;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        $productTypeValues = implode(',', array_column(ProductType::cases(), 'value'));
        $productUnitValues = implode(',', array_column(ProductUnit::cases(), 'value'));

        return [
            ImportColumn::make('name')
                ->label('Artikelnaam')
                ->exampleHeader('Artikelnaam')
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('uid')
                ->label('Artikelnummer')
                ->exampleHeader('Artikelnummer')
                ->requiredMapping()
                ->rules(['required', 'max:255']),

            ImportColumn::make('type')
                ->label('Type artikel')
                ->exampleHeader('Type artikel')
                ->requiredMapping()
                ->castStateUsing(fn (?string $state): ?string => self::resolveEnumValue($state, ProductType::cases()))
                ->rules(['required', 'in:' . $productTypeValues]),

            ImportColumn::make('unit')
                ->label('Eenheid')
                ->exampleHeader('Eenheid')
                ->castStateUsing(fn (?string $state): ?string => self::resolveEnumValue($state, ProductUnit::cases()))
                ->rules(['nullable', 'in:' . $productUnitValues]),

            ImportColumn::make('chair_type')
                ->label('Type')
                ->exampleHeader('Type')
                ->rules(['nullable', 'max:255']),

            ImportColumn::make('description')
                ->label('Specificaties')
                ->exampleHeader('Specificaties')
                ->rules(['nullable']),

            ImportColumn::make('comment')
                ->label('Interne opmerking')
                ->exampleHeader('Interne opmerking')
                ->rules(['nullable']),

            ImportColumn::make('supplier_product_name')
                ->label('Artikelnaam leverancier')
                ->exampleHeader('Artikelnaam leverancier')
                ->rules(['nullable', 'max:255']),

            ImportColumn::make('supplier_product_uid')
                ->label('Artikelnummer leverancier')
                ->exampleHeader('Artikelnummer leverancier')
                ->rules(['nullable', 'max:255']),

            ImportColumn::make('company_purchase_price')
                ->label('Inkoop excl. BTW')
                ->exampleHeader('Inkoop excl. BTW')
                ->castStateUsing(fn (?string $state): ?float => self::parseDecimal($state))
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('company_sales_price')
                ->label('Verkoop excl. BTW')
                ->exampleHeader('Verkoop excl. BTW')
                ->castStateUsing(fn (?string $state): ?float => self::parseDecimal($state))
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('company_margin')
                ->label('Marge %')
                ->exampleHeader('Marge %')
                ->castStateUsing(fn (?string $state): ?float => self::parseDecimal($state))
                ->rules(['nullable', 'numeric']),

            ImportColumn::make('company_markup')
                ->label('Opslag %')
                ->exampleHeader('Opslag %')
                ->castStateUsing(fn (?string $state): ?float => self::parseDecimal($state))
                ->rules(['nullable', 'numeric']),

            ImportColumn::make('is_stock_enabled')
                ->label('Voorraad product')
                ->exampleHeader('Voorraad product')
                ->castStateUsing(fn (?string $state): ?bool => self::parseBoolean($state))
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('is_fraction_allowed_item')
                ->label('Deelbaar')
                ->exampleHeader('Deelbaar')
                ->castStateUsing(fn (?string $state): ?bool => self::parseBoolean($state))
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('is_purchase_item')
                ->label('Inkoop')
                ->exampleHeader('Inkoop')
                ->castStateUsing(fn (?string $state): ?bool => self::parseBoolean($state))
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('is_sales_item')
                ->label('Verkoop')
                ->exampleHeader('Verkoop')
                ->castStateUsing(fn (?string $state): ?bool => self::parseBoolean($state))
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('is_on_demand_item')
                ->label('Ordergestuurd')
                ->exampleHeader('Ordergestuurd')
                ->castStateUsing(fn (?string $state): ?bool => self::parseBoolean($state))
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('exact_article_group_id')
                ->label('Artikelgroep (Exact)')
                ->exampleHeader('Artikelgroep (Exact)')
                ->requiredMapping()
                ->castStateUsing(fn (?string $state): ?int => self::resolveArticleGroupId($state))
                ->rules(['required', 'integer', 'exists:exact_article_groups,id']),

            ImportColumn::make('exact_sales_vat_code_id')
                ->label('Verkoop-BTW (Exact)')
                ->exampleHeader('Verkoop-BTW (Exact)')
                ->requiredMapping()
                ->castStateUsing(fn (?string $state): ?int => self::resolveSalesVatCodeId($state))
                ->rules(['required', 'integer', 'exists:exact_vat_codes,id']),
        ];
    }

    public function resolveRecord(): Product
    {
        return new Product();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'De artikelimport is voltooid. ' . $import->successful_rows . ' ' . ($import->successful_rows === 1 ? 'rij' : 'rijen') . ' geïmporteerd.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . $failedRowsCount . ' ' . ($failedRowsCount === 1 ? 'rij' : 'rijen') . ' mislukt.';
        }

        return $body;
    }

    /**
     * Resolves an enum value or its Dutch label to the canonical enum value.
     *
     * @param array<\BackedEnum> $cases
     */
    private static function resolveEnumValue(?string $state, array $cases): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        foreach ($cases as $case) {
            if ($case->value === $state) {
                return $case->value;
            }
        }

        foreach ($cases as $case) {
            if (method_exists($case, 'getLabel') && $case->getLabel() === $state) {
                return $case->value;
            }
        }

        return $state;
    }

    /**
     * Parses Dutch-formatted decimals (comma as decimal separator, dot as thousands separator).
     */
    private static function parseDecimal(?string $state): ?float
    {
        if ($state === null || $state === '') {
            return null;
        }

        // "1.234,56" → "1234.56", "135,00" → "135.00"
        $normalized = str_replace(['.', ','], ['', '.'], $state);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * Parses Dutch boolean values (Ja/Nee) as well as standard 1/0 and true/false.
     */
    private static function parseBoolean(?string $state): ?bool
    {
        if ($state === null || $state === '') {
            return null;
        }

        return match (strtolower(trim($state))) {
            'ja', 'yes', '1', 'true' => true,
            'nee', 'no', '0', 'false' => false,
            default => null,
        };
    }

    /**
     * Resolves an article group name (e.g. "026 : Smartdrive") to its database ID.
     */
    private static function resolveArticleGroupId(?string $state): ?int
    {
        if ($state === null || $state === '') {
            return null;
        }

        return ExactArticleGroup::query()
            ->where('name', $state)
            ->value('id');
    }

    /**
     * Resolves a sales VAT code string (e.g. "2 : BTW 21% excl." or just "2") to its database ID.
     */
    private static function resolveSalesVatCodeId(?string $state): ?int
    {
        if ($state === null || $state === '') {
            return null;
        }

        // Accept full export format "code : name" or just the code
        $code = str_contains($state, ' : ') ? trim(explode(' : ', $state)[0]) : trim($state);

        return ExactVATCode::getSalesVatCodes()
            ->where('code', $code)
            ->first()
            ?->id;
    }
}

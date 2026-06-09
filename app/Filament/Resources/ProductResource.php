<?php

namespace App\Filament\Resources;

use App\Actions\PriceAdjustBulkAction;
use App\Enums\OrderType;
use App\Enums\OrderSubtype;
use App\Enums\OrderProductStatus;
use App\Enums\ProductBattery;
use App\Enums\ProductUnit;
use App\Enums\AddressType;
use App\Filament\Resources\ProductResource\Widgets\ProductPriceChangesWidget;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use Filament\Actions\Action;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Tables\Columns\ProductNameColumn;
use App\Models\ExactArticleGroup;
use App\Models\ExactVATCode;
use App\Models\Address;
use App\Models\Customer;
use App\Models\OrderProduct;
use Exception;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use App\Models\Product;
use App\Support\Pricing\ProductPricingCalculator;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Table;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\JsContent;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Group;
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $breadcrumb = 'Alle artikelen';
    protected static ?string $modelLabel = 'artikel';
    protected static ?string $pluralModelLabel = 'artikelen';
    protected static ?string $recordTitleAttribute = 'name';
    protected static ?string $slug = 'products';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage products') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function editUrlFor(Product|int|null $product): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $productId = $product instanceof Product ? $product->getKey() : $product;

        if ($productId === null) {
            return null;
        }

        return static::getUrl('edit', ['record' => $productId]);
    }

    /**
     * Product codes are matched case-insensitively (e.g. rd.w9600… vs RD.W9600…).
     */
    protected static ?bool $isGlobalSearchForcedCaseInsensitive = true;

    public bool $active;

    public static function resolveRecordRouteBinding(string|int $key, ?\Closure $modifyQuery = null): ?Model
    {
        /** @var \Illuminate\Database\Eloquent\Model $query */
        $query = app(static::getModel())
            ->resolveRouteBindingQuery(static::getEloquentQuery(), $key, static::getRecordRouteKeyName());
        return $query
            ->with(['exactSalesVatCode', 'exactPurchaseVatCode', 'stock'])
            ->first();
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery();
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'uid',
            'name',
            'exact_id',
        ];
    }

    public static function getGlobalSearchResultTitle(Model $record): string | Htmlable
    {
        /** @var Product $record */
        $name = $record->getName() ?? (string) $record->getKey();

        return $name;
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Product $record */
        $details = [
            'Artikelnummer' => $record->uid,
        ];

        return array_filter($details, fn (string $v): bool => $v !== '');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->extraAttributes(['class' => 'companySection-wrapper'])
            ->components([

                View::make('filament.components.back-to-overview-with-heading')
                    ->viewData([
                        'title' => 'Artikel-overzicht',
                        'url' => route('filament.app.resources.products.index'),
                        'class' => 'extraMargin',
                    ]),
                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make('Artikelgegevens')
                            ->schema([

                                // ------------------------TAB------------------------------------

                                Section::make('')
                                    ->extraAttributes(['class' => 'companySection'])
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([
                                                        Section::make('Algemene Artikelgegevens')
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->schema([
                                                                JsContent::make(<<<'JS'
                                                                    document.body.classList.add('save-button-enabled');
                                                                JS
                                                                ),
                                                                Checkbox::make('is_eol')
                                                                    ->label('EOL')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('uid')
                                                                    ->label('Artikelnummer')
                                                                    ->columnSpan(6)
                                                                    ->extraAttributes(['style' => 'white-space: nowrap;'])
                                                                    ->inlineLabel(),
                                                                TextInput::make('name')
                                                                    ->label('Omschrijving')
                                                                    ->required()
                                                                    ->columnSpan(6)
                                                                    ->extraAttributes(['style' => 'white-space: nowrap;'])
                                                                    ->inlineLabel(),
                                                                TextInput::make('description2')
                                                                    ->label('Omschrijving 2')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('search_code')
                                                                    ->label('Zoekgegeven')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                Select::make('brand')
                                                                    ->label('Merk')
                                                                    ->columnSpan(6)
                                                                    ->options([
                                                                        'JL' => 'JL - JLAB',
                                                                    ])
                                                                    ->inlineLabel(),
                                                                TextInput::make('product_group')
                                                                    ->label('Productgroep')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('sub_group')
                                                                    ->label('Subgroep')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('manufacturer')
                                                                    ->label('Fabrikant')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('stock_location')
                                                                    ->label('Magazijnlokatie')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                Section::make('Specificaties (voor op offerte/order)')
                                                                    ->extraAttributes(['class' => 'spec-border'])
                                                                    ->columnSpan(6)
                                                                    ->schema([
                                                                        Textarea::make('description')
                                                                            ->hiddenLabel()
                                                                            ->rows(6)
                                                                            ->columnSpanFull()
                                                                            ->extraInputAttributes(['style' => 'width: 100%;']),
                                                                    ]),
                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([
                                                        Section::make('Algemene Artikelgegevens2')
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->schema([
                                                                TextInput::make('mediamarkt_nr_nl')
                                                                    ->label('MediaMarktnummer NL')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('mediamarkt_nr_bnl')
                                                                    ->label('MeidMarktnummer: Belu')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('ean_1')
                                                                    ->label('EANcode1')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('ean_2')
                                                                    ->label('EANcode2')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('dl_code')
                                                                    ->label('DLcode')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('hs_code')
                                                                    ->label('HScode')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('krefel_nr')
                                                                    ->label('Krefelnummer')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('bol_nr')
                                                                    ->label('BOLnummer')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                TextInput::make('coolblue_nr')
                                                                    ->label('Coolbluenummer')
                                                                    ->columnSpan(6)
                                                                    ->inlineLabel(),
                                                                Select::make('unit')
                                                                    ->label('Verkoopeenheid')
                                                                    ->columnSpan(6)
                                                                    ->options(ProductUnit::labels())
                                                                    ->inlineLabel(),
                                                                Select::make('battery')
                                                                    ->label('Accu')
                                                                    ->columnSpan(6)
                                                                    ->options(ProductBattery::labels())
                                                                    ->inlineLabel(),
                                                                TextInput::make('pcb')
                                                                    ->label('PCB')
                                                                    ->columnSpan(6)
                                                                    ->numeric()
                                                                    ->step(0.001)
                                                                    ->inlineLabel(),
                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([
                                                        Section::make('Voorraad')
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->schema([
                                                                Group::make([
                                                                    TextInput::make('physical_stock')
                                                                        ->label('Voorraad')
                                                                        ->inlineLabel()
                                                                        ->default(0)
                                                                        ->required()
                                                                        ->numeric()
                                                                        ->columnSpanFull(),

                                                                    TextInput::make('reserved_stock')
                                                                        ->label('In bestelling')
                                                                        ->inlineLabel()
                                                                        ->extraAttributes(['style' => 'white-space: nowrap;'])
                                                                        ->required()
                                                                        ->default(0)
                                                                        ->columnSpanFull()
                                                                        ->numeric(),

                                                                    TextInput::make('available_stock')
                                                                        ->label('Beschikbare voorraad')
                                                                        ->inlineLabel()
                                                                        ->columnSpanFull()
                                                                        ->disabled()
                                                                        ->default(0)
                                                                        ->dehydrated(false),

                                                                    Select::make('allow_backorder')
                                                                        ->label('Back-orders toestaan')
                                                                        ->inlineLabel()
                                                                        ->default('1')
                                                                        ->options([
                                                                            '1' => 'Ja',
                                                                            '0' => 'Nee',
                                                                        ])
                                                                        ->formatStateUsing(function ($state): string {
                                                                            if ($state === true || $state === 1 || $state === '1') {
                                                                                return '1';
                                                                            }

                                                                            return '0';
                                                                        })
                                                                        ->dehydrateStateUsing(fn ($state): bool => (string) $state === '1')
                                                                        ->selectablePlaceholder(false)
                                                                        ->required()
                                                                        ->columnSpanFull(),

                                                                    TextInput::make('min_threshold')
                                                                        ->label('Minimumvoorraad')
                                                                        ->inlineLabel()
                                                                        ->default(0)
                                                                        ->columnSpanFull()
                                                                        ->required()
                                                                        ->numeric(),
                                                                ])
                                                                    ->columnSpan(6)
                                                                    ->relationship('stock'),


                                                            ]),
                                                    ]),
                                            ]),
                                        Grid::make(3)
                                            ->extraAttributes(['class' => 'section-border'])
                                            ->schema([
                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([
                                                        Section::make('Prijs')
                                                            ->visible(fn(Get $get): bool => !$get('is_bundle_parent') && !$get('is_group_product'))
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->schema([
                                                                TextInput::make('company_purchase_price')
                                                                    ->numeric()
                                                                    ->prefix('€')
                                                                    ->label(new HtmlString('Inkoop <span class="taxOverview">(excl. BTW)</span>'))
                                                                    ->inlineLabel()
                                                                    ->live()
                                                                    ->afterStateUpdated(fn(Get $get, Set $set) => self::syncRatesFromPurchaseAndSales($get, $set))
                                                                    ->columnSpan(3)
                                                                    ->default(0),

                                                                TextInput::make('company_sales_price')
                                                                    ->numeric()
                                                                    ->prefix('€')
                                                                    ->label(new HtmlString('Verkoop <span class="taxOverview">(excl. BTW)</span>'))
                                                                    ->inlineLabel()
                                                                    ->live()
                                                                    ->afterStateUpdated(fn(Get $get, Set $set) => self::syncRatesFromPurchaseAndSales($get, $set))
                                                                    ->columnSpan(3)
                                                                    ->default(0),

                                                                TextInput::make('company_margin')
                                                                    ->numeric()
                                                                    ->suffix('%')
                                                                    ->label(new HtmlString('Marge <span class="taxOverview">(%)</span>'))
                                                                    ->inlineLabel()
                                                                    ->readOnly()
                                                                    ->dehydrated(false)
                                                                    ->extraInputAttributes(['disabled' => 'disabled'])
                                                                    ->columnSpan(3)
                                                                    ->default(0),

                                                                TextInput::make('company_markup')
                                                                    ->numeric()
                                                                    ->suffix('%')
                                                                    ->label(new HtmlString('Opslag <span class="taxOverview">(%)</span>'))
                                                                    ->inlineLabel()
                                                                    ->readOnly()
                                                                    ->dehydrated(false)
                                                                    ->extraInputAttributes(['disabled' => 'disabled'])
                                                                    ->columnSpan(3)
                                                                    ->default(0),

                                                                TextInput::make('price_change_comment')
                                                                    ->label('Prijswijziging opmerking')
                                                                    ->maxLength(1000)
                                                                    ->inlineLabel()
                                                                    ->columnSpan(4)
                                                                    ->visible(function (Get $get, ?Product $record): bool {
                                                                        if ($record === null) {
                                                                            return false;
                                                                        }

                                                                        $currentPurchase = (float)($get('company_purchase_price') ?? 0);
                                                                        $currentSales = (float)($get('company_sales_price') ?? 0);
                                                                        $currentMargin = (float)($get('company_margin') ?? 0);
                                                                        $currentMarkup = (float)($get('company_markup') ?? 0);
                                                                        $originalPurchase = (float)($record->getOriginal('company_purchase_price') ?? $record->company_purchase_price ?? 0);
                                                                        $originalSales = (float)($record->getOriginal('company_sales_price') ?? $record->company_sales_price ?? 0);
                                                                        $originalMargin = (float)($record->getOriginal('company_margin') ?? $record->company_margin ?? 0);
                                                                        $originalMarkup = (float)($record->getOriginal('company_markup') ?? $record->company_markup ?? 0);

                                                                        return abs($currentPurchase - $originalPurchase) > 0.0001
                                                                            || abs($currentSales - $originalSales) > 0.0001
                                                                            || abs($currentMargin - $originalMargin) > 0.0001
                                                                            || abs($currentMarkup - $originalMarkup) > 0.0001;
                                                                    })
                                                                    ->dehydrated()
                                                                    ->dehydratedWhenHidden(),
                                                            ]),
                                                    ]),
                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->schema([
                                                        Section::make('Informatie artikel (intern)')
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->schema([
                                                                Textarea::make('comment')
                                                                    ->hiddenLabel()
                                                                    ->rows(9)
                                                                    ->maxLength(5000)
                                                                    ->columnSpanFull(),
                                                            ]),
                                                    ]),
                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->extraAttributes(['class' => 'document-component'])
                                                    ->schema([
                                                        Section::make('Documenten')
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->schema([
                                                                View::make('filament.resources.product-resource.partials.documents-block')
                                                                    ->viewData(fn (?Product $record): array => [
                                                                        'record' => $record,
                                                                    ]),
                                                            ]),
                                                    ]),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('eigenschappen')
                            ->label('Eigenschappen')
                            ->schema([
                                Section::make('')
                                    ->extraAttributes(['class' => 'companySection'])
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Toggle::make('is_fraction_allowed_item')
                                                    ->label('Deelbaar')
                                                    ->inlineLabel()
                                                    ->columnSpan(1),
                                                Toggle::make('is_purchase_item')
                                                    ->label('Inkoop')
                                                    ->inlineLabel()
                                                    ->columnSpan(1),
                                                Toggle::make('is_sales_item')
                                                    ->label('Verkoop')
                                                    ->inlineLabel()
                                                    ->columnSpan(1),
                                                Toggle::make('is_on_demand_item')
                                                    ->label('Ordergestuurd')
                                                    ->inlineLabel()
                                                    ->columnSpan(1),
                                            ]),
                                    ]),
                            ]),

                        Tab::make('price_changes')
                            ->label('Prijswijzigingen')
                            ->visibleOn('edit')
                            ->schema([
                                Livewire::make(ProductPriceChangesWidget::class)
                                    ->visibleOn('edit')
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Afbeeldingen')
                            ->extraAttributes(['class' => 'hideLabel'])
                            ->schema([

                                // ------------------------TAB------------------------------------

                                SpatieMediaLibraryFileUpload::make('images')
                                    ->multiple()
                                    ->columnSpanFull()
                                    ->reorderable()
                                    ->image()
                                    ->imageResizeMode('crop')
                                    ->preserveFilenames()
                                    ->panelLayout('compact')
                                    ->label('Afbeeldingen')
                                    ->extraAttributes(['class' => 'file-upload upload-grid']),
                            ]),


                        Tab::make('Exact koppeling')
                            ->hidden(fn(Get $get): bool => (bool)$get('is_group_product'))
                            ->schema([

                                // ------------------------TAB------------------------------------
                                Grid::make(12)
                                    ->schema([
                                        Grid::make(1)
                                            ->columnSpan(4)
                                            ->schema([
                                                Section::make('Exact koppeling')
                                                    ->extraAttributes(['class' => 'exactSection exactproduct'])
                                                    ->schema([
                                                        Text::make('Een nieuw product of aanpassing (update) van het product wordt na opslaan binnen een uur gesynchroniseerd met Exact.')
                                                            ->columnSpanFull()
                                                            ->extraAttributes(['style' => 'display: block;']),

                                                        Select::make('exact_article_group_id')
                                                            ->label('Artikelgroep')
                                                            ->required()
                                                            ->reactive()
                                                            ->columnSpan(4)
                                                            ->inlineLabel()
                                                            ->hidden(fn(Get $get) => (bool)$get('is_group_product'))
                                                            ->options(
                                                                fn() => ExactArticleGroup::query()
                                                                    ->distinct()
                                                                    ->pluck('name', 'id')
                                                                    ->toArray()
                                                            ),

                                                        Select::make('exact_sales_vat_code_id')
                                                            ->label('Verkoop-BTW')
                                                            ->options(function (): array {
                                                                return ExactVATCode::getSalesVatCodes()
                                                                    ->mapWithKeys(fn(ExactVATCode $v): array => [
                                                                        $v->id => $v->code . ' : ' . $v->name,
                                                                    ])
                                                                    ->all();
                                                            })
                                                            ->required()
                                                            ->searchable()
                                                            ->columnSpan(4)
                                                            ->inlineLabel(),

                                                        TextInput::make('exact_id')
                                                            ->label('Exact Online ID')
                                                            ->hidden(fn(Get $get) => $get('is_group_product'))
                                                            ->columnSpan(4)
                                                            ->inlineLabel()
                                                            ->disabled(),

                                                        Text::make(
                                                            fn(?Product $record) => !empty($record)
                                                                ? 'Laatst gesynchroniseerd: ' . ($record?->getExactSyncedAt() ? $record->getExactSyncedAt()->translatedFormat('d M Y H:i') : 'nooit') . ''
                                                                : ''
                                                        )
                                                            ->columnSpanFull()
                                                            ->extraAttributes(['style' => 'display: block; margin-top: 22px; margin-bottom: 0px;']),
                                                    ])
                                            ])
                                    ]),
                            ]),
                    ]),

                // ------------------------TAB------------------------------------

            ]);
    }

    /**
     * @throws Exception
     */

    public static function table(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->headerActions([
                Action::make('product')
                    ->label('Artikel aanmaken')
                    ->url(route('filament.app.resources.products.create'))
                    ->icon('heroicon-s-plus-circle'),
            ])
            ->extraAttributes(['class' => 'searchAlignLeft'])
            ->columns([
                TextColumn::make('uid')
                    ->label('Artikelnummer')
                    ->sortable()
                    ->searchable()
                    ->forceSearchCaseInsensitive(),

                TextColumn::make('name')
                    ->label('Omschrijving')
                    ->sortable()
                    ->searchable()
                    ->limit(40)
                    ->width(220)
                    ->tooltip(fn (Product $record): ?string => filled($record->name) ? (string) $record->name : null),

            ])
            ->defaultSort('name', 'asc')
            ->deferFilters(false)
            ->filters([
                self::getActiveFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([])
            ->toolbarActions([
                PriceAdjustBulkAction::make('price_adjust'),
            ]);
    }

    public static function syncSalesFromPurchaseAndMargin(Get $get, Set $set): void
    {
        $purchase = self::normalizeFloat($get('company_purchase_price'));
        $margin = self::normalizeFloat($get('company_margin'));
        $sales = ProductPricingCalculator::recalculateSalesFromPurchaseAndMargin($purchase, $margin);

        $set(
            'company_sales_price',
            $sales,
            shouldCallUpdatedHooks: false
        );

        $set(
            'company_markup',
            ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales($purchase, $sales),
            shouldCallUpdatedHooks: false
        );
    }

    public static function syncSalesFromPurchaseAndMarkup(Get $get, Set $set): void
    {
        $purchase = self::normalizeFloat($get('company_purchase_price'));
        $markup = self::normalizeFloat($get('company_markup'));
        $sales = ProductPricingCalculator::recalculateSalesFromPurchaseAndMarkup($purchase, $markup);

        $set(
            'company_sales_price',
            $sales,
            shouldCallUpdatedHooks: false
        );

        $set(
            'company_margin',
            ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($purchase, $sales),
            shouldCallUpdatedHooks: false
        );
    }

    public static function syncRatesFromPurchaseAndSales(Get $get, Set $set): void
    {
        $purchase = self::normalizeFloat($get('company_purchase_price'));
        $sales = self::normalizeFloat($get('company_sales_price'));

        $set(
            'company_margin',
            ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($purchase, $sales),
            shouldCallUpdatedHooks: false
        );

        $set(
            'company_markup',
            ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales($purchase, $sales),
            shouldCallUpdatedHooks: false
        );
    }

    private static function normalizeFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        if (!is_string($value) || $value === '') {
            return 0.0;
        }

        return (float)str_replace(',', '.', str_replace('.', '', $value));
    }

    public function isActive(): bool
    {
        return (bool)$this->active;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    /**
     * @throws Exception
     */
    protected function getActions(): array
    {
        return [
            Action::make('settings')->action('openSettingsModal'),
        ];
    }
}

<?php

namespace App\Filament\Resources;

use App\Actions\PriceAdjustBulkAction;
use App\Enums\ArticleGroupGlAccountType;
use App\Enums\OrderType;
use App\Enums\OrderSubtype;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\AddressType;
use App\Enums\OrderProductStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\ProductResource\Widgets\ProductPurchaseDocumentsWidget;
use App\Filament\Resources\ProductResource\Widgets\ProductPriceChangesWidget;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Support\PurchaseAuthorization;
use Filament\Actions\Action;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Tables\Columns\ProductNameColumn;
use App\Models\ExactArticleGroup;
use App\Models\ExactVATCode;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order\StockOrder;
use App\Models\OrderProduct;
use App\Models\Order\Order;
use Exception;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Pricing\ProductPricingCalculator;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Html;
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
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

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
            ->with(['supplier', 'exactSalesVatCode', 'exactPurchaseVatCode'])
            ->first();
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with('supplier');
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'uid',
            'supplier_product_uid',
            'name',
            'supplier_product_name',
            'exact_id',
            'supplier.name',
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

        if (filled($record->supplier_product_uid)) {
            $details['Artikelnummer leverancier'] = $record->supplier_product_uid;
        }

        if ($record->supplier !== null && filled($record->supplier->name)) {
            $details['Leverancier'] = $record->supplier->name;
        }

        return array_filter($details, fn (string $v): bool => $v !== '');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function form(Schema $schema): Schema
    {
        $frameChairTypeOptions = Product::frameChairTypeOptions();
        $suppliers = Supplier::all();
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
                                                                TextInput::make('name')
                                                                    ->label('Artikelnaam RD Mobility')
                                                                    ->columnSpan(6)
                                                                    ->required()
                                                                    ->extraAttributes(['style' => 'white-space: nowrap;'])
                                                                    ->inlineLabel(),
                                                                TextInput::make('uid')
                                                                    ->label('Artikelnummer RD Mobility')
                                                                    ->columnSpan(6)
                                                                    ->required()
                                                                    ->inlineLabel(),


                                                                Select::make('type')
                                                                    ->label('Type artikel')
                                                                    ->columnSpan(6)
                                                                    ->options(ProductType::labels())
                                                                    ->required()
                                                                    ->inlineLabel()
                                                                    ->live()
                                                                    ->afterStateUpdated(function ($state, Set $set): void {
                                                                        $value = $state instanceof ProductType
                                                                            ? $state->value
                                                                            : (string)$state;
                                                                        if ($value !== ProductType::Frame->value) {
                                                                            $set('chair_type', null);
                                                                        }
                                                                    }),

                                                                Select::make('chair_type')
                                                                    ->label('Type')
                                                                    ->required()
                                                                    ->columnSpan(6)
                                                                    ->options($frameChairTypeOptions)
                                                                    ->visible(fn(Get $get): bool => (($get('type') instanceof ProductType)
                                                                        ? $get('type') === ProductType::Frame
                                                                        : (string)$get('type') === ProductType::Frame->value))
                                                                    ->inlineLabel(),

                                                                Select::make('unit')
                                                                    ->label('Eenheid')
                                                                    ->columnSpan(6)
                                                                    ->options(ProductUnit::labels())
                                                                    ->required()
                                                                    ->inlineLabel()
                                                                    ->visible(fn(Get $get): bool => ($get('type') instanceof ProductType)
                                                                        ? $get('type') === ProductType::Service
                                                                        : (string)($get('type') ?? '') === ProductType::Service->value),

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
                                                    ->visible(fn(Get $get): bool => (($get('type') instanceof ProductType)
                                                        ? $get('type') !== ProductType::Service
                                                        : (string)($get('type') ?? '') !== ProductType::Service->value))
                                                    ->schema([
                                                        Section::make('Leverancier')
                                                            ->extraAttributes(['class' => 'beheer-productInstellingenSection'])
                                                            ->schema([
                                                                Group::make()
                                                                    ->columns(1)
                                                                    ->columnSpanFull()
                                                                    ->schema([

                                                                        Grid::make()
                                                                            ->inlineLabel()
                                                                            ->columnSpan(6)
                                                                            ->schema(function (Get $get) use ($suppliers) {
                                                                                return [
                                                                                    Select::make('supplier_id')
                                                                                        ->relationship('supplier', 'name')
                                                                                        ->label('Leverancier')
                                                                                        ->inlineLabel()
                                                                                        ->columnSpan(6)
                                                                                        ->searchable()
                                                                                        ->preload()
                                                                                        ->reactive(),


                                                                                    TextInput::make('supplier_product_name')
                                                                                        ->label('Artikelnaam leverancier')
                                                                                        ->dehydratedWhenHidden(true)
                                                                                        ->inlineLabel()
                                                                                        ->columnSpan(6),

                                                                                    TextInput::make('supplier_product_uid')
                                                                                        ->label('Artikelnummer leverancier')
                                                                                        ->dehydratedWhenHidden(true)
                                                                                        ->extraAttributes(['style' => 'white-space: nowrap;'])
                                                                                        ->inlineLabel()
                                                                                        ->columnSpan(6),

                                                                                    Select::make('exact_purchase_vat_code_id')
                                                                                        ->label('Inkoop-BTW')
                                                                                        ->options(function (): array {
                                                                                            return ExactVATCode::getPurchaseVatCodes()
                                                                                                ->mapWithKeys(fn(ExactVATCode $v): array => [
                                                                                                    $v->id => $v->code . ' : ' . $v->name,
                                                                                                ])
                                                                                                ->all();
                                                                                        })
                                                                                        ->searchable()
                                                                                        ->nullable()
                                                                                        ->columnSpan(6)
                                                                                        ->inlineLabel(),

                                                                                    Select::make('unit')
                                                                                        ->label('Inkoopeenheid')
                                                                                        ->columnSpan(6)
                                                                                        ->options(ProductUnit::labels())
                                                                                        ->required()
                                                                                        ->inlineLabel(),
                                                                                ];
                                                                            }),

                                                                    ]),
                                                            ]),
                                                    ]),

                                                Grid::make(1)
                                                    ->columnSpan(1)
                                                    ->visible(fn(Get $get): bool => (($get('type') instanceof ProductType)
                                                        ? $get('type') !== ProductType::Service
                                                        : (string)($get('type') ?? '') !== ProductType::Service->value))
                                                    ->schema([
                                                        Section::make('Voorraad')
                                                            ->extraAttributes(['class' => 'beheer-algemeenSection'])
                                                            ->afterHeader([
                                                                Html::make(function ($livewire): string {
                                                                    if (! PurchaseAuthorization::canManage()) {
                                                                        return '';
                                                                    }

                                                                    $product = ($livewire->record ?? null) instanceof Product
                                                                        ? $livewire->record
                                                                        : null;

                                                                    if (! self::canCreateStockOrderForProduct($product)) {
                                                                        return '';
                                                                    }

                                                                    $url = self::stockOrderCreateUrlForProduct($product);

                                                                    return '<a target="_blank" class="header-custom-product-link main-request-number-link hover:underline" href="' . e($url) . '">Inkooporder aanmaken</a>';
                                                                })
                                                                    ->columnSpanFull(),
                                                            ])
                                                            ->schema([
                                                                Select::make('is_stock_enabled')
                                                                    ->label('Voorraad product')
                                                                    ->inlineLabel()
                                                                    ->options([
                                                                        '1' => 'Ja',
                                                                        '0' => 'Nee',
                                                                    ])
                                                                    ->default('0')
                                                                    ->formatStateUsing(function ($state): string {
                                                                        if ($state === true || $state === 1 || $state === '1') {
                                                                            return '1';
                                                                        }

                                                                        return '0';
                                                                    })
                                                                    ->dehydrateStateUsing(fn($state): int => (string)$state === '1' ? 1 : 0)
                                                                    ->selectablePlaceholder(false)
                                                                    ->live()
                                                                    ->columnSpan(6)
                                                                    ->required()
                                                                    ->visible(fn(Get $get) => !$get('is_group_child')),

                                                                Group::make([
                                                                    TextInput::make('physical_stock')
                                                                        ->label('Fysieke voorraad')
                                                                        ->inlineLabel()
                                                                        ->default(0)
                                                                        ->required()
                                                                        ->numeric()
                                                                        ->columnSpanFull()
                                                                        ->visible(fn(Get $get): bool => (bool)$get('../is_stock_enabled')),

                                                                    TextInput::make('reserved_stock')
                                                                        ->label('Gereserveerde voorraad')
                                                                        ->inlineLabel()
                                                                        ->extraAttributes(['style' => 'white-space: nowrap;'])
                                                                        ->required()
                                                                        ->default(0)
                                                                        ->columnSpanFull()
                                                                        ->numeric()
                                                                        ->visible(fn(Get $get): bool => (bool)$get('../is_stock_enabled')),

                                                                    TextInput::make('available_stock')
                                                                        ->label('Beschikbare voorraad')
                                                                        ->inlineLabel()
                                                                        ->columnSpanFull()
                                                                        ->disabled()
                                                                        ->default(0)
                                                                        ->dehydrated(false)
                                                                        ->visible(fn(Get $get): bool => (bool)$get('../is_stock_enabled')),

                                                                    Select::make('allow_backorder')
                                                                        ->label('Back-orders toestaan')
                                                                        ->inlineLabel()
                                                                        ->default('1')
                                                                        ->options([
                                                                            '1' => 'Ja',
                                                                            '0' => 'Nee',
                                                                        ])
                                                                        ->selectablePlaceholder(false)
                                                                        ->required()
                                                                        ->columnSpanFull()
                                                                        ->visible(fn(Get $get): bool => (bool)$get('../is_stock_enabled')),

                                                                    TextInput::make('min_threshold')
                                                                        ->label('Minimumvoorraad')
                                                                        ->inlineLabel()
                                                                        ->default(0)
                                                                        ->columnSpanFull()
                                                                        ->required()
                                                                        ->numeric()
                                                                        ->visible(fn(Get $get): bool => (bool)$get('../is_stock_enabled')),
                                                                ])
                                                                    ->columnSpan(6)
                                                                    ->relationship('stock'),

                                                                TextInput::make('average_lead_time')
                                                                    ->label('Gemiddelde levertijd')
                                                                    ->formatStateUsing(fn(?Product $record): string => $record?->getAverageLeadTimeHuman() ?? '-')
                                                                    ->readOnly()
                                                                    ->inlineLabel()
                                                                    ->columnSpan(6)
                                                                    ->dehydrated(false)
                                                                    ->extraInputAttributes(['disabled' => 'disabled']),

                                                                TextInput::make('purchase_count')
                                                                    ->label('Lopende inkopen')
                                                                    ->formatStateUsing(
                                                                        static fn(?Product $record): string => $record === null
                                                                            ? '0'
                                                                            : (int)OrderProduct::query()
                                                                                ->where('product_id', $record->id)
                                                                                ->whereIn('status', [
                                                                                    PurchaseOrderStatus::Purchased->value,
                                                                                    PurchaseOrderStatus::Confirmed->value
                                                                                ])
                                                                                ->whereNull('delivered_at')
                                                                                ->sum('qty')
                                                                    )
                                                                    ->readOnly()
                                                                    ->inlineLabel()
                                                                    ->columnSpan(6)
                                                                    ->dehydrated(false)
                                                                    ->extraInputAttributes(['disabled' => 'disabled']),
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

                        Tab::make('purchase_docs')
                            ->label('Inkoopdocumenten')
                            ->visible(fn (): bool => PurchaseAuthorization::canManage())
                            ->visibleOn('edit')
                            ->schema([
                                Livewire::make(ProductPurchaseDocumentsWidget::class)
                                    ->visibleOn('edit')
                                    ->columnSpanFull(),
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

                                                        ...self::makeGlAccountFields(),

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
                    ->label('Artikelnummer RD Mobility')
                    ->sortable()
                    ->searchable()
                    ->forceSearchCaseInsensitive(),

                TextColumn::make('name')
                    ->label('Artikelnaam RD Mobility')
                    ->sortable()
                    ->searchable()
                    ->limit(40)
                    ->width(220)
                    ->tooltip(fn (Product $record): ?string => filled($record->name) ? (string) $record->name : null),

                TextColumn::make('supplier.name')
                    ->label('Leverancier')
                    ->sortable()
                    ->searchable()
                    ->limit(30)
                    ->width(150)
                    ->tooltip(fn (Product $record): ?string => $record->supplier?->name)
                    ->placeholder('—'),

                TextColumn::make('supplier_product_uid')
                    ->label('Artikelnummer Leverancier')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('supplier_product_name')
                    ->label('Artikelnaam Leverancier')
                    ->sortable()
                    ->searchable()
                    ->limit(40)
                    ->width(220)
                    ->tooltip(fn (Product $record): ?string => filled($record->supplier_product_name) ? (string) $record->supplier_product_name : null)
                    ->placeholder('—'),

                TextColumn::make('type')
                    ->label('Type artikel')
                    ->formatStateUsing(fn ($state): ?string => $state?->getLabel())
                    ->sortable(),

                TextColumn::make('chair_type')
                    ->label('Type unit')
                    ->formatStateUsing(fn (?string $state): string => Product::getFrameChairTypeLabel($state))
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('name', 'asc')
            ->deferFilters(false)
            ->filters([
                self::getSupplierFilter('supplier'),
                self::createStatusFilter('product_type', 'type', 'Type artikel', ProductType::labels()),
                self::createStatusFilter('chair_type', 'chair_type', 'Type unit', Product::frameChairTypeOptions()),
                self::getActiveFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([])
            ->toolbarActions([
                PriceAdjustBulkAction::make('price_adjust'),
            ]);
    }

    public static function canCreateStockOrderForProduct(?Product $product): bool
    {
        return $product !== null
            && $product->getKey()
            && filled($product->supplier_id)
            && (bool) $product->is_purchase_item;
    }

    public static function stockOrderCreateUrlForProduct(?Product $product): string
    {
        if (self::canCreateStockOrderForProduct($product)) {
            return route('filament.app.resources.stock-orders.create-from-product', ['product' => $product->getKey()]);
        }

        return route('filament.app.resources.stock-orders.create');
    }

    /**
     * @param Collection<int, Product> $products
     */
    public static function createStockOrderForProducts(int $supplierId, Collection $products): StockOrder
    {
        $rdCustomer = Customer::getRdMobilityCustomer();
        $shippingSource = $rdCustomer->billingAddress;
        $shippingName = $rdCustomer->getName();

        $stockOrder = StockOrder::create([
            'type'                 => 'stock_order',
            'billing_customer_id'  => $rdCustomer->id,
            'shipping_customer_id' => $rdCustomer->id,
            'status'               => PurchaseOrderStatus::Draft,
            'author_id'            => auth()->id(),
        ]);
        $stockOrder->setSupplierId($supplierId);

        $additional = $stockOrder->getAdditional() ?? [];

        if ($shippingSource !== null) {
            $addressSnapshot = [
                'street'                => $shippingSource->street,
                'house_number'          => $shippingSource->house_number,
                'house_number_addition' => $shippingSource->house_number_addition,
                'postcode'              => $shippingSource->postcode,
                'city'                  => $shippingSource->city,
                'country_id'            => $shippingSource->country_id,
            ];

            $additional = array_merge($additional, [
                'billing_address_type_key'  => 'rd',
                'billing_name'                => $shippingName,
                'invoice_address'             => $addressSnapshot,
                'shipping_address_type_key'   => 'rd',
                'shipping_name'               => $shippingName,
                'delivery_address'            => $addressSnapshot,
            ]);
        }

        $stockOrder->setAdditional($additional);
        $stockOrder->save();

        foreach ($products->filter(fn (Product $product): bool => (bool) $product->is_purchase_item) as $product) {
            $companyPurchasePrice = round((float)($product->getCompanyPurchasePrice() ?? 0), 2);
            $companySalesPrice = round((float)($product->getCompanySalesPrice() ?? 0), 2);

            OrderProduct::create([
                'order_id' => $stockOrder->id,
                'product_id' => $product->getId(),
                'supplier_id' => $supplierId,
                'value' => $product->getName(),
                'qty' => 1,
                'status' => OrderProductStatus::Initial,
                'company_purchase_price_base' => $companyPurchasePrice,
                'company_purchase_price_additional' => 0,
                'company_purchase_price_subtotal' => $companyPurchasePrice,
                'company_purchase_price_total' => $companyPurchasePrice,
                'company_sales_price_base' => $companySalesPrice,
                'company_sales_price_additional' => 0,
                'company_sales_price_subtotal' => $companySalesPrice,
                'company_sales_price_total' => $companySalesPrice,
                'vat' => 21.00,
            ]);
        }

        return $stockOrder;
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

    /**
     * @return array<int, \Filament\Forms\Components\TextInput>
     */
    private static function makeGlAccountFields(): array
    {
        $resolveLabel = function (Get $get, ArticleGroupGlAccountType $type): ?string {
            $groupId = $get('exact_article_group_id');
            if (!$groupId) {
                return null;
            }

            return ExactArticleGroup::query()
                ->with('glAccountLinks.glAccount')
                ->find($groupId)
                ?->getGlAccountLabel($type);
        };

        return array_map(
            fn(ArticleGroupGlAccountType $type) => TextInput::make('gl_account_' . $type->value)
                ->label($type->getLabel())
                ->columnSpan(4)
                ->inlineLabel()
                ->placeholder(fn(Get $get): ?string => $resolveLabel($get, $type))
                ->disabled()
                ->dehydrated(false),
            ArticleGroupGlAccountType::cases(),
        );
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

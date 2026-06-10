<?php

namespace App\Models;

use App\Enums\ProductBattery;
use App\Enums\ProductBrand;
use App\Support\Pricing\ProductPricingCalculator;
use App\Support\ProductSelectSearchConstraints;
use App\Enums\ProductUnit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Traits\ActiveTrait;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;


/**
 * @property int $id
 * @property string $uid
 * @property bool $is_eol
 * @property string $name
 * @property string|null $description
 * @property string|null $description2
 * @property string|null $search_code
 * @property-read string|null $search_text
 * @property string|null $brand
 * @property string|null $sub_group
 * @property string|null $manufacturer
 * @property string|null $stock_location
 * @property string|null $mediamarkt_nr_nl
 * @property string|null $mediamarkt_nr_bnl
 * @property string|null $ean_1
 * @property string|null $ean_2
 * @property string|null $dl_code
 * @property string|null $hs_code
 * @property string|null $krefel_nr
 * @property string|null $bol_nr
 * @property string|null $coolblue_nr
 * @property ProductBattery|null $battery
 * @property string|null $pcb
 * @property string|null $comment
 * @property string|null $unit
 * @property numeric $company_margin RD | Marge (gross margin % of sales, same as Exact)
 * @property numeric $company_markup RD | Opslag (markup % of purchase)
 * @property numeric $company_purchase_price RD | Inkoop
 * @property numeric $company_sales_price RD | Verkoop
 * @property int $is_stock_enabled
 * @property int|null $category_id
 * @property int|null $supplier_id
 * @property string|null $supplier_product_uid
 * @property string|null $supplier_product_name
 * @property string|null $exact_id
 * @property Carbon|null $exact_synced_at
 * @property array<array-key, mixed>|null $config
 * @property string|null $exact_item_group_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $deleted_at
 * @property int|null $exact_article_group_id
 * @property int|null $exact_sales_vat_code_id
 * @property int|null $exact_purchase_vat_code_id
 * @property string|null $exact_supplier_item_id
 * @property bool $is_fraction_allowed_item
 * @property bool $is_purchase_item
 * @property bool $is_sales_item
 * @property bool $is_on_demand_item
 * @property-read ExactArticleGroup|null $exactArticleGroup
 * @property-read ExactVATCode|null $exactSalesVatCode
 * @property-read ExactVATCode|null $exactPurchaseVatCode
 * @property-read string $name_short
 * @property-read MediaCollection<int, Media> $media
 * @property-read int|null $media_count
 * @property-read \App\Models\ProductStock|null $stock
 * @property-read Collection<int, \App\Models\StockMovement> $stockMovements
 * @property-read int|null $stock_movements_count
 * @method static Builder<static>|Product active()
 * @method static Builder<static>|Product selectableForOrderOrQuote()
 * @method static Builder<static>|Product newModelQuery()
 * @method static Builder<static>|Product newQuery()
 * @method static Builder<static>|Product query()
 * @method static Builder<static>|Product whereCategoryId($value)
 * @method static Builder<static>|Product whereCompanyMargin($value)
 * @method static Builder<static>|Product whereCompanyMarkup($value)
 * @method static Builder<static>|Product whereCompanyPurchasePrice($value)
 * @method static Builder<static>|Product whereCompanySalesPrice($value)
 * @method static Builder<static>|Product whereConfig($value)
 * @method static Builder<static>|Product whereCreatedAt($value)
 * @method static Builder<static>|Product whereDeletedAt($value)
 * @method static Builder<static>|Product whereDescription($value)
 * @method static Builder<static>|Product whereExactArticleGroupId($value)
 * @method static Builder<static>|Product whereExactId($value)
 * @method static Builder<static>|Product whereExactItemGroupId($value)
 * @method static Builder<static>|Product whereExactSupplierItemId($value)
 * @method static Builder<static>|Product whereExactSyncedAt($value)
 * @method static Builder<static>|Product whereId($value)
 * @method static Builder<static>|Product whereIsStockEnabled($value)
 * @method static Builder<static>|Product whereName($value)
 * @method static Builder<static>|Product whereSupplierId($value)
 * @method static Builder<static>|Product whereSupplierProductName($value)
 * @method static Builder<static>|Product whereUid($value)
 * @method static Builder<static>|Product whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Product extends Model implements HasMedia
{
    use InteractsWithMedia,
        ActiveTrait,
        SoftDeletes;

    public ?array $price_change_action_context = null;

    protected $fillable = [
        'uid',
        'is_eol',
        'name',
        'description',
        'description2',
        'search_code',
        'brand',
        'sub_group',
        'manufacturer',
        'stock_location',
        'mediamarkt_nr_nl',
        'mediamarkt_nr_bnl',
        'ean_1',
        'ean_2',
        'dl_code',
        'hs_code',
        'krefel_nr',
        'bol_nr',
        'coolblue_nr',
        'battery',
        'pcb',
        'comment',
        'unit',
        'company_purchase_price',
        'company_sales_price',
        'company_margin',
        'company_markup',
        'supplier_product_uid',
        'supplier_product_name',
        'exact_item_group_id',
        'category_id',
        'supplier_id',
        'exact_id',
        'exact_synced_at',
        'config',
        'additional',
        'is_stock_enabled',
        'exact_article_group_id',
        'exact_sales_vat_code_id',
        'exact_purchase_vat_code_id',
        'exact_supplier_item_id',
        'is_fraction_allowed_item',
        'is_purchase_item',
        'is_sales_item',
        'is_on_demand_item',
    ];

    protected function casts(): array
    {
        return [
            'unit' => ProductUnit::class,
            'brand' => ProductBrand::class,
            'battery' => ProductBattery::class,
            'pcb' => 'decimal:6',
            'config' => 'array',
            'additional' => 'array',
            'exact_synced_at' => 'datetime',
            'deleted_at' => 'datetime',
            'is_eol' => 'boolean',
            'is_fraction_allowed_item' => 'boolean',
            'is_purchase_item' => 'boolean',
            'is_sales_item' => 'boolean',
            'is_on_demand_item' => 'boolean',
        ];
    }

    protected function searchText(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->search_code,
            set: fn (?string $value): array => ['search_code' => $value],
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $product->is_stock_enabled = 1;

            if ($product->isDirty('company_purchase_price') || $product->isDirty('company_sales_price')) {
                $purchase = (float) ($product->company_purchase_price ?? 0);
                $sales = (float) ($product->company_sales_price ?? 0);
                $product->company_margin = ProductPricingCalculator::recalculateMarginFromPurchaseAndSales($purchase, $sales);
                $product->company_markup = ProductPricingCalculator::recalculateMarkupFromPurchaseAndSales($purchase, $sales);
            }
        });
    }

    public function shouldBeSyncedToExact(): bool
    {
        return !str_ends_with($this->name, '-kopie');
    }

    public function exactArticleGroup(): BelongsTo
    {
        return $this->belongsTo(ExactArticleGroup::class, 'exact_article_group_id');
    }

    public function exactSalesVatCode(): BelongsTo
    {
        return $this->belongsTo(ExactVATCode::class, 'exact_sales_vat_code_id');
    }

    public function exactPurchaseVatCode(): BelongsTo
    {
        return $this->belongsTo(ExactVATCode::class, 'exact_purchase_vat_code_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Order/quote lines: require a supplier except for service products.
     *
     * @param Builder<Product> $query
     * @return Builder<Product>
     */
    public function scopeSelectableForOrderOrQuote(Builder $query): Builder
    {
        return $query->whereNotNull('supplier_id');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeMatchingSelectSearch(Builder $query, string $search): Builder
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            $term = '%'.addcslashes($word, '%_\\').'%';

            $query->where(function (Builder $q) use ($term): void {
                $q->where('uid', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('supplier_product_uid', 'like', $term)
                    ->orWhere('supplier_product_name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('supplier', fn (Builder $supplierQuery): Builder => $supplierQuery->where('name', 'like', $term));
            });
        }

        return $query;
    }

    public const UID_PRELOAD_MATCH_LENGTH = 7;

    /**
     * First {@see UID_PRELOAD_MATCH_LENGTH} characters of the UID for preload grouping.
     */
    public static function uidPrefixForSequentialPreload(string $uid): string
    {
        $uid = trim($uid);

        if ($uid === '') {
            return '';
        }

        return mb_substr($uid, 0, self::UID_PRELOAD_MATCH_LENGTH);
    }

    /**
     * @return array<int|string, string>
     */
    public static function optionsForSelectSearch(string $search, ?ProductSelectSearchConstraints $constraints = null): array
    {
        $constraints ??= new ProductSelectSearchConstraints();

        $query = static::query()->with('supplier');

        if ($constraints->supplierId !== null) {
            $query->where('supplier_id', $constraints->supplierId);
        }

        if ($constraints->restrictToProductIds !== null) {
            if ($constraints->restrictToProductIds === []) {
                return [];
            }

            $query->whereIn('id', $constraints->restrictToProductIds);
        }

        if ($constraints->salesItemsOnly) {
            $query->where('is_sales_item', true);
        }

        if ($constraints->purchaseItemsOnly) {
            $query->where('is_purchase_item', true);
        }

        if (trim($search) === '') {
            $anchorProduct = null;

            if ($constraints->anchorProductId !== null) {
                $anchorProduct = static::query()->withTrashed()->find($constraints->anchorProductId);
            }

            if ($anchorProduct instanceof Product) {
                $anchorUid = $anchorProduct->getUid();

                if ($anchorUid !== '') {
                    $uidPrefix = static::uidPrefixForSequentialPreload($anchorUid);

                    if ($uidPrefix !== '') {
                        $escapedPrefix = addcslashes($uidPrefix, '%_\\');
                        $query->where('uid', 'like', $escapedPrefix.'%');
                    }

                    $query->where('uid', '>', $anchorUid)
                        ->orderBy('uid')
                        ->limit(50);

                    $options = $query->get()->mapWithKeys(fn (Product $product): array => [
                        $product->getId() => $product->getSelectOptionLabel(),
                    ])->all();

                    return [
                        $anchorProduct->getId() => $anchorProduct->getSelectOptionLabel(),
                        ...$options,
                    ];
                }
            }

            $query->orderBy('uid')->limit(50);
        } else {
            $query->matchingSelectSearch($search)->orderBy('name')->limit(50);
        }

        return $query->get()->mapWithKeys(fn (Product $product): array => [
            $product->getId() => $product->getSelectOptionLabel(),
        ])->all();
    }

    public static function getSelectOptionLabelForId(mixed $value): string
    {
        if (! filled($value)) {
            return '';
        }

        $product = static::query()->withTrashed()->with('supplier')->find((int) $value);

        return $product instanceof Product ? $product->getSelectOptionLabel() : '';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->withResponsiveImages();
        $this->addMediaCollection('documents');
    }

    public function stock(): HasOne|Builder
    {
        return $this->hasOne(ProductStock::class);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('xxs')
            ->fit(Fit::Fill, 45, 45)
            ->nonQueued();
        $this
            ->addMediaConversion('xs')
            ->fit(Fit::Fill, 85, 85)
            ->nonQueued();
        $this
            ->addMediaConversion('sm')
            ->fit(Fit::Max, 100, 150)
            ->nonQueued();
        $this
            ->addMediaConversion('medium-small')
            ->fit(Fit::Contain, 180, 180)
            ->nonQueued();
        $this
            ->addMediaConversion('medium')
            ->fit(Fit::Fill, 220, 240)
            ->nonQueued();
        $this
            ->addMediaConversion('medium-large')
            ->fit(Fit::Fill, 400, 215)
            ->nonQueued();
        $this
            ->addMediaConversion('large')
            ->fit(Fit::FillMax, 530, 450)
            ->nonQueued();
    }


    public function getNameShortAttribute(): string
    {
        return Str::limit($this->getName(), 20);
    }

    public function getImage(string $conversion = ''): ?string
    {
        return $this->getMedia('default')?->last()?->getUrl($conversion);
    }

    public function useProductBuilder(): bool
    {
        return false;
    }

    public function isOutOfStock(): bool
    {
        return $this->getIsStockEnabled() && ! $this->stock?->isInStock();
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return Product
     */
    public function setName(string $name): Product
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getUid(): string
    {
        return $this->uid ?? '';
    }

    /**
     * Select option label: uid | name | supplier_name
     */
    public function getSelectOptionLabel(): string
    {
        $supplier = $this->supplier?->name ?? 'Zonder leverancier';

        return $this->getUid().' | '.$this->getName().' | '.$supplier;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    /**
     * The product code used in Exact Online (maps to the Item Code field).
     */
    public function getExactCode(): string
    {
        return $this->uid ?? '';
    }

    /**
     * @param string $uid
     * @return Product
     */
    public function setUid(string $uid): Product
    {
        $this->uid = $uid;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     * @return Product
     */
    public function setDescription(?string $description): Product
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return ProductUnit|null
     */
    public function getUnit(): ?ProductUnit
    {
        return $this->unit;
    }

    /**
     * @param ProductUnit|null $unit
     * @return Product
     */
    public function setUnit(?ProductUnit $unit): Product
    {
        $this->unit = $unit;
        return $this;
    }

    /**
     * @return string
     */
    public function getPriceType(): string
    {
        return $this->price_type;
    }

    /**
     * @param string $price_type
     * @return Product
     */
    public function setPriceType(string $price_type): Product
    {
        $this->price_type = $price_type;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getIsIndividuallyVisible(): ?int
    {
        return $this->is_individually_visible;
    }

    /**
     * @param int|null $is_individually_visible
     * @return Product
     */
    public function setIsIndividuallyVisible(?int $is_individually_visible): Product
    {
        $this->is_individually_visible = $is_individually_visible;
        return $this;
    }

    public function getIsVisiblePortal(): bool
    {
        return $this->is_visible_portal;
    }

    public function setIsVisiblePortal(bool $is_visible_portal): Product
    {
        $this->is_visible_portal = $is_visible_portal;
        return $this;
    }


    /**
     * @return int|null
     */
    public function getSupplierId(): ?int
    {
        return $this->supplier_id;
    }

    /**
     * @param int|null $supplier_id
     * @return Product
     */
    public function setSupplierId(?int $supplier_id): Product
    {
        $this->supplier_id = $supplier_id;
        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    /**
     * @return Carbon|null
     */
    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }

    /**
     * @return string|null
     */
    public function getDeletedAt(): ?string
    {
        return $this->deleted_at;
    }

    /**
     * @param string|null $deleted_at
     * @return Product
     */
    public function setDeletedAt(?string $deleted_at): Product
    {
        $this->deleted_at = $deleted_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getNameShort(): string
    {
        return $this->name_short;
    }

    /**
     * @return int|null
     */
    public function getMediaCount(): ?int
    {
        return $this->media_count;
    }

    /**
     * @return string|null
     */
    public function getExactId(): ?string
    {
        return $this->exact_id;
    }

    /**
     * @param string|null $exact_id
     * @return Product
     */
    public function setExactId(?string $exact_id): Product
    {
        $this->exact_id = $exact_id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getExactItemGroupId(): ?string
    {
        return $this->exact_item_group_id;
    }

    /**
     * @param string|null $exact_item_group_id
     * @return Product
     */
    public function setExactItemGroupId(?string $exact_item_group_id): Product
    {
        $this->exact_item_group_id = $exact_item_group_id;
        return $this;
    }

    public function getExactArticleGroupId(): ?int
    {
        return $this->exact_article_group_id;
    }

    public function setExactArticleGroupId(?int $exact_article_group_id): Product
    {
        $this->exact_article_group_id = $exact_article_group_id;
        return $this;
    }

    public function getExactSupplierItemId(): ?string
    {
        return $this->exact_supplier_item_id;
    }

    public function setExactSupplierItemId(?string $exact_supplier_item_id): Product
    {
        $this->exact_supplier_item_id = $exact_supplier_item_id;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getCompanyMargin(): ?float
    {
        return $this->company_margin;
    }

    /**
     * @param float|null $company_margin
     * @return Product
     */
    public function setCompanyMargin(?float $company_margin): Product
    {
        $this->company_margin = $company_margin;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getCompanyMarkup(): ?float
    {
        return $this->company_markup;
    }

    /**
     * @param float|null $company_markup
     * @return Product
     */
    public function setCompanyMarkup(?float $company_markup): Product
    {
        $this->company_markup = $company_markup;
        return $this;
    }


    /**
     * @return float|null
     */
    public function getCompanyPurchasePrice(): ?float
    {
        return $this->company_purchase_price;
    }

    /**
     * @param float|null $company_purchase_price
     * @return Product
     */
    public function setCompanyPurchasePrice(?float $company_purchase_price): Product
    {
        $this->company_purchase_price = $company_purchase_price;
        return $this;
    }

    /**
     * @return float|null
     */
    public function getCompanySalesPrice(): ?float
    {
        return $this->company_sales_price;
    }

    /**
     * @param float|null $company_sales_price
     * @return Product
     */
    public function setCompanySalesPrice(?float $company_sales_price): Product
    {
        $this->company_sales_price = $company_sales_price;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSupplierProductName(): ?string
    {
        return $this->supplier_product_name;
    }

    /**
     * @return string|null
     */
    public function getSupplierProductUid(): ?string
    {
        return $this->supplier_product_uid;
    }

    /**
     * @param string|null $supplier_product_uid
     * @return Product
     */
    public function setSupplierProductUid(?string $supplier_product_uid): Product
    {
        $this->supplier_product_uid = $supplier_product_uid;
        return $this;
    }

    /**
     * @param string|null $supplier_product_name
     * @return Product
     */
    public function setSupplierProductName(?string $supplier_product_name): Product
    {
        $this->supplier_product_name = $supplier_product_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getBymichelProductId(): ?string
    {
        return $this->bymichel_product_id;
    }

    /**
     * @param string|null $bymichel_product_id
     * @return Product
     */
    public function setBymichelProductId(?string $bymichel_product_id): Product
    {
        $this->bymichel_product_id = $bymichel_product_id;
        return $this;
    }



    /**
     * @param string|null $key
     * @return array|string|null
     */
    public function getConfig(?string $key = null): array|string|null
    {
        return $key && isset($this->config[$key])
            ? $this->config[$key]
            : $this->config;
    }

    /**
     * @param array $config
     * @return Product
     */
    public function setConfig(array $config): Product
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getItemOptions(): ?array
    {
        return $this->item_options;
    }

    /**
     * @param array|null $item_options
     * @return Product
     */
    public function setItemOptions(?array $item_options): Product
    {
        $this->item_options = $item_options;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getSupplierParams(): ?array
    {
        return $this->supplier_params;
    }

    /**
     * @param array|null $supplier_params
     * @return Product
     */
    public function setSupplierParams(?array $supplier_params): Product
    {
        $this->supplier_params = $supplier_params;
        return $this;
    }

    public function getIsGroupChild(): bool
    {
        return $this->is_group_child;
    }

    public function setIsGroupChild(bool $is_group_child): Product
    {
        $this->is_group_child = $is_group_child;
        return $this;
    }


    /**
     * @return bool|null
     */
    public function getIsGroupProduct(): ?bool
    {
        return $this->is_group_product;
    }

    /**
     * @param bool|null $is_group_product
     * @return Product
     */
    public function setIsGroupProduct(?bool $is_group_product): Product
    {
        $this->is_group_product = $is_group_product;
        return $this;
    }


    public function getIsStockEnabled(): ?bool
    {
        return $this->is_stock_enabled;
    }

    public function setIsStockEnabled(?bool $is_stock_enabled): Product
    {
        $this->is_stock_enabled = $is_stock_enabled;
        return $this;
    }

    public function getExactSyncedAt(): ?Carbon
    {
        return $this->exact_synced_at;
    }

    public function setExactSyncedAt(?Carbon $exact_synced_at): Product
    {
        $this->exact_synced_at = $exact_synced_at;
        return $this;
    }

    public function getVideoUrl(): ?string
    {
        return $this->video_url;
    }

    public function setVideoUrl(?string $video_url): Product
    {
        $this->video_url = $video_url;
        return $this;
    }

    public function requiresProductBuilder(): bool
    {
        return false;
    }

    public function getAverageLeadTimeSeconds(): ?int
    {
        return null;
    }

    public function getAverageLeadTimeHuman(): string
    {
        $seconds = $this->getAverageLeadTimeSeconds();
        if ($seconds === null || $seconds <= 0) {
            return '-';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        if ($days > 0 && $hours > 0) {
            return $days . ' dag(en), ' . $hours . ' uur';
        }

        if ($days > 0) {
            return $days . ' dag(en)';
        }

        if ($hours > 0) {
            return $hours . ' uur';
        }

        $minutes = max(1, intdiv($seconds, 60));

        return $minutes . ' min';
    }

}


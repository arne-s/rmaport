<?php

namespace App\Models;

use App\Models\Order\StockOrder;
use App\Traits\ActiveTrait;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\Supplier
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $contact_email
 * @property string|null $email_supplier
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property string|null $phone_number
 * @property string|null $mobile_number
 * @property int $is_active
 * @property string|null $class
 * @property string|null $reference
 * @property int $sync_with_exact
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Product> $products
 * @property-read int|null $products_count
 * @property-read Collection<int, StockOrder> $stockOrders
 * @property-read int|null $stock_orders_count
 * @method static Builder|Supplier active()
 * @method static Builder|Supplier newModelQuery()
 * @method static Builder|Supplier newQuery()
 * @method static Builder|Supplier query()
 * @method static Builder|Supplier whereClass($value)
 * @method static Builder|Supplier whereCreatedAt($value)
 * @method static Builder|Supplier whereId($value)
 * @method static Builder|Supplier whereIsActive($value)
 * @method static Builder|Supplier whereName($value)
 * @method static Builder|Supplier whereUpdatedAt($value)
 * @method static Builder|Supplier whereReference($value)
 * @property mixed|null $admin_fields
 * @method static Builder|Supplier whereAdminFields($value)
 * @mixin Eloquent
 * @property string|null $exact_code
 * @property string|null $exact_id
 * @property string|null $kvk_number
 * @property string|null $vat_number
 * @property int|null $exact_payment_condition_id
 * @property int|null $exact_gl_account_id
 * @property int|null $exact_vat_code_id
 * @property string|null $street
 * @property string|null $house_number
 * @property string|null $postcode
 * @property string|null $city
 * @property int|null $country_id
 * @property Carbon|null $last_synced_at
 * @property-read Country|null $country
 * @method static Builder|Supplier whereCity($value)
 * @method static Builder|Supplier whereCountryId($value)
 * @method static Builder|Supplier whereExactCode($value)
 * @method static Builder|Supplier whereExactGlAccountId($value)
 * @method static Builder|Supplier whereExactId($value)
 * @method static Builder|Supplier whereExactPaymentConditionId($value)
 * @method static Builder|Supplier whereExactVatCodeId($value)
 * @method static Builder|Supplier whereHouseNumber($value)
 * @method static Builder|Supplier whereKvkNumber($value)
 * @method static Builder|Supplier whereLastSyncedAt($value)
 * @method static Builder|Supplier wherePostcode($value)
 * @method static Builder|Supplier whereStreet($value)
 * @method static Builder|Supplier whereVatNumber($value)
 * @method static Builder|Supplier whereSyncWithExact($value)
 * @mixin \Eloquent
 */
class Supplier extends Model
{
    use ActiveTrait;

    const BYMICHEL_SUPPLIER_ID = 1;

    protected $fillable = [
        'is_active',
        'class',
        'admin_fields',
        'name',
        'email',
        'contact_email',
        'email_supplier',
        'first_name',
        'middle_name',
        'last_name',
        'phone_number',
        'mobile_number',
        'reference',
        'sync_with_exact',
        'exact_code',
        'exact_id',
        'kvk_number',
        'vat_number',
        'exact_payment_condition_id',
        'exact_gl_account_id',
        'exact_vat_code_id',
        'street',
        'house_number',
        'postcode',
        'city',
        'country_id',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'admin_fields' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function stockOrders(): HasMany
    {
        return $this->hasMany(StockOrder::class);
    }

    public function isUserSelectable() : bool
    {
        return $this->getClassInstance()::$properties['userSelectable'] ?? false;
    }

    /**
     * PDF suppliers do not require a product builder
     *
     * @return bool
     */
    public function getIsProductBuilderRequired(): bool
    {
        return $this->getClass() !== 'App\Services\OrderSync\Supplier\Pdf';
    }

    public static function getClasses(): array
    {
       return [];
    }

    public static function rules(): array
    {
        return [
            'name' => 'required|unique:suppliers',
        ];
    }

    public function getAdminField(string $field): ?string
    {
        if (isset($this->admin_fields[$field])) {
            return $this->admin_fields[$field];
        }

        return null;
    }

    public function getEmail(): ?string
    {
        return $this->getEmailSupplier();
    }

    public function setEmail(?string $email): self
    {
        return $this->setEmailSupplier($email);
    }

    public function getEmailSupplier(): ?string
    {
        return $this->email_supplier ?? $this->email;
    }

    public function setEmailSupplier(?string $email): self
    {
        $this->email_supplier = $email;

        return $this;
    }

    public function getContactEmailAttribute(): ?string
    {
        return $this->attributes['contact_email'] ?? $this->email_supplier;
    }

    public function setContactEmailAttribute(?string $email): void
    {
        $this->attributes['contact_email'] = $email;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function exactPaymentCondition(): BelongsTo
    {
        return $this->belongsTo(ExactPaymentCondition::class, 'exact_payment_condition_id');
    }

    public function exactGlAccount(): BelongsTo
    {
        return $this->belongsTo(ExactGLAccount::class, 'exact_gl_account_id');
    }

    public function exactVatCode(): BelongsTo
    {
        return $this->belongsTo(ExactVATCode::class, 'exact_vat_code_id');
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return Supplier
     */
    public function setId(int $id): Supplier
    {
        $this->id = $id;
        return $this;
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
     * @return Supplier
     */
    public function setName(string $name): Supplier
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return int
     */
    public function getIsActive(): int
    {
        return $this->is_active;
    }

    /**
     * @param int $is_active
     * @return Supplier
     */
    public function setIsActive(int $is_active): Supplier
    {
        $this->is_active = $is_active;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getClass(): ?string
    {
        return $this->class;
    }

    /**
     * @param string|null $class
     * @return Supplier
     */
    public function setClass(?string $class): Supplier
    {
        $this->class = $class;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getReference(): ?string
    {
        return $this->reference;
    }

    /**
     * @param string|null $reference
     * @return Supplier
     */
    public function setReference(?string $reference): Supplier
    {
        $this->reference = $reference;
        return $this;
    }

    /**
     * @return Collection
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    /**
     * @param Collection $products
     * @return Supplier
     */
    public function setProducts(Collection $products): Supplier
    {
        $this->products = $products;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getProductsCount(): ?int
    {
        return $this->products_count;
    }

    /**
     * @param int|null $products_count
     * @return Supplier
     */
    public function setProductsCount(?int $products_count): Supplier
    {
        $this->products_count = $products_count;
        return $this;
    }

    public function getAddress()
    {
        return trim(implode(' ', [
            $this->street ?? '',
            $this->house_number ?? '',
        ]));
    }


    /**
     * Get the full name of the contact.
     *
     * @return string
     */
    public function getFullName(): string
    {
        return implode(' ',
            array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name,
            ])
        );
    }
}

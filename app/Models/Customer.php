<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PaymentTerms;
use App\Models\ExactVATCode;
use App\Models\Order\Order;
use App\Observers\CustomerObserver;
use App\Services\NewsletterSubscriptionWriter;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property CustomerStatus $status
 * @property CustomerType|null $type
 * @property string|null $email
 * @property string|null $salutation
 * @property string|null $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property int|null $address_id
 * @property string|null $phone_number
 * @property string|null $mobile_phone_number
 * @property string|null $comment
 * @property PaymentTerms|null $payment_terms
 * @property string|null $reason_inactive
 * @property string|null $exact_payment_condition
 * @property string|null $exact_vat_code
 * @property string|null $exact_id
 * @property string|null $debtor_number
 * @property Carbon|null $exact_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Address|null $address
 * @property-read Country|null $country
 * @property string|null $name
 * @property-read string $full_name
 * @property-read string $name_reversed
 * @property-read Region|null $region
 * @property-read Collection<int, \App\Models\Note> $notes
 * @property-read int|null $notes_count
 * @property-read Collection<int, Order> $orders
 * @property-read int|null $orders_count
 * @method static Builder<static>|Customer newModelQuery()
 * @method static Builder<static>|Customer newQuery()
 * @method static Builder<static>|Customer query()
 * @method static Builder<static>|Customer whereAddressId($value)
 * @method static Builder<static>|Customer whereComment($value)
 * @method static Builder<static>|Customer whereCreatedAt($value)
 * @method static Builder<static>|Customer whereEmail($value)
 * @method static Builder<static>|Customer whereExactPaymentCondition($value)
 * @method static Builder<static>|Customer whereExactSyncedAt($value)
 * @method static Builder<static>|Customer whereExactVatCode($value)
 * @method static Builder<static>|Customer whereFirstName($value)
 * @method static Builder<static>|Customer whereId($value)
 * @method static Builder<static>|Customer whereIsDepositRequired($value)
 * @method static Builder<static>|Customer whereLastName($value)
 * @method static Builder<static>|Customer whereMiddleName($value)
 * @method static Builder<static>|Customer whereMobilePhoneNumber($value)
 * @method static Builder<static>|Customer wherePhoneNumber($value)
 * @method static Builder<static>|Customer whereReasonInactive($value)
 * @method static Builder<static>|Customer whereSalutation($value)
 * @method static Builder<static>|Customer whereStatus($value)
 * @method static Builder<static>|Customer whereType($value)
 * @method static Builder<static>|Customer whereUpdatedAt($value)
 * @mixin \Eloquent
 */
#[ObservedBy([CustomerObserver::class])]
class Customer extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'customers';

    protected $attributes = [
        'exact_vat_code' => ExactVATCode::DEFAULT_SALES_VAT_CODE,
        'newsletter_subscribed' => true,
    ];

    protected $fillable = [
        'status',
        'type',
        'email',
        'salutation',
        'first_name',
        'middle_name',
        'last_name',
        'dob',
        'name',
        'vat',
        'kvk',
        'discount_percentage',
        'iban',
        'bic',
        'address_id',
        'billing_address_id',
        'shipping_address_id',
        'delivery_address_type',
        'phone_number',
        'mobile_phone_number',
        'payment_terms',
        'reason_inactive',
        'exact_payment_condition',
        'exact_vat_code',
        'exact_id',
        'debtor_number',
        'exact_synced_at',
        'comment',
        'newsletter_subscribed',
    ];

    protected $casts = [
        'status' => CustomerStatus::class,
        'type' => CustomerType::class,
        'payment_terms' => PaymentTerms::class,
        'dob' => 'date',
        'exact_synced_at' => 'datetime',
        'newsletter_subscribed' => 'boolean',
        'discount_percentage' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $vat = $customer->exact_vat_code;
            if ($vat === null || trim((string) $vat) === '') {
                $customer->exact_vat_code = ExactVATCode::DEFAULT_SALES_VAT_CODE;
            }

            if (
                $customer->isDirty('status')
                && $customer->getStatus() === CustomerStatus::Inactive
                && $customer->getOriginal('status') !== CustomerStatus::Inactive
            ) {
                NewsletterSubscriptionWriter::clearStoredNewsletterPreferences($customer);
            }
        });
    }

    /**
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            CustomerStatus::Initial->value,
            CustomerStatus::Inactive->value,
        ]);
    }

    public static function rules(): array
    {
        return [
            'first_name' => ['nullable'],
            'last_name' => ['required'],
            'middle_name' => ['nullable'],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'salutation' => ['nullable'],
            'email' => ['email'],
            'phone_number' => ['nullable', 'digits_between:1,12'],
            'mobile_phone_number' => ['nullable', 'digits_between:1,12'],
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('documents');
    }

    /**
     * Get the full name of the customer.
     *
     * @return string
     */
    public function getNameReversedAttribute(): string
    {
        return $this->last_name . ', ' .
            $this->first_name . ' ' .
            $this->middle_name;
    }


    public function getIsTest(): bool
    {
        return false;
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return MorphMany<NewsletterSubscription, $this>
     */
    public function newsletterSubscriptions(): MorphMany
    {
        return $this->morphMany(NewsletterSubscription::class, 'subscribable');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id');
    }

    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    /**
     * Physical delivery address: billing when delivery matches invoice details, otherwise the separate shipping address.
     */
    public function getPhysicalDeliveryAddress(): ?Address
    {
        $this->loadMissing(['shippingAddress', 'billingAddress', 'address']);

        $type = $this->delivery_address_type ?? 'contact';

        if ($type === 'contact') {
            return $this->billingAddress ?? $this->address;
        }

        return $this->shippingAddress ?? $this->billingAddress ?? $this->address;
    }

    public function getShippingAddress(): ?Address
    {
        return $this->getPhysicalDeliveryAddress();
    }

    /**
     * Address used for invoicing: {@see $billingAddress} when set, otherwise legacy {@see $address}.
     */
    public function getInvoiceAddress(): ?Address
    {
        $this->loadMissing(['billingAddress', 'address']);

        return $this->billingAddress ?? $this->address;
    }

    public static function addressHasMeaningfulContent(?Address $address): bool
    {
        if ($address === null) {
            return false;
        }

        return filled($address->street)
            || filled($address->house_number)
            || filled($address->house_number_addition)
            || filled($address->postcode)
            || filled($address->city);
    }

    /**
     * Ensure {@see $billing_address_id} and {@see $shipping_address_id} are set after Filament/CSV saves.
     * Promotes legacy {@see $address_id} to billing; mirrors billing onto shipping when delivery matches invoice.
     */
    public function ensureBillingAndShippingAddressLinks(): void
    {
        $this->loadMissing(['address', 'billingAddress', 'shippingAddress']);

        if (
            $this->billing_address_id === null
            && $this->address_id !== null
            && self::addressHasMeaningfulContent($this->address)
        ) {
            $this->billing_address_id = $this->address_id;
        }

        $billing = $this->billingAddress ?? $this->address;
        if ($billing === null || ! self::addressHasMeaningfulContent($billing)) {
            return;
        }

        if ($this->address_id === null) {
            $this->address_id = $this->billing_address_id;
        }

        if (($this->delivery_address_type ?? 'contact') !== 'contact') {
            if ($this->isDirty(['billing_address_id', 'address_id'])) {
                $this->saveQuietly();
            }

            return;
        }

        $shipping = $this->shippingAddress;

        if (
            $this->shipping_address_id === null
            || $this->shipping_address_id === $this->billing_address_id
            || ! self::addressHasMeaningfulContent($shipping)
        ) {
            $newShipping = Address::copyFrom($billing);
            $newShipping->forceFill(['customer_id' => $this->getKey()])->saveQuietly();
            $this->shipping_address_id = $newShipping->getKey();
            $shipping = $newShipping;
        } else {
            self::copyAddressFields($billing, $shipping);
            $shipping->forceFill(['customer_id' => $this->getKey()])->saveQuietly();
        }

        if ($this->isDirty(['billing_address_id', 'shipping_address_id', 'address_id'])) {
            $this->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function addressFormArrayHasContent(array $fields): bool
    {
        return filled($fields['street'] ?? null)
            || filled($fields['house_number'] ?? null)
            || filled($fields['house_number_addition'] ?? null)
            || filled($fields['postcode'] ?? null)
            || filled($fields['city'] ?? null);
    }

    private static function copyAddressFields(Address $source, Address $target): void
    {
        $target->street = $source->street;
        $target->house_number = $source->house_number;
        $target->house_number_addition = $source->house_number_addition;
        $target->postcode = $source->postcode;
        $target->city = $source->city;
        $target->country_id = $source->country_id;
        $target->region_id = $source->region_id;

        if (filled($source->name)) {
            $target->name = $source->name;
        }

        if (filled($source->location_name)) {
            $target->location_name = $source->location_name;
        }
    }

    /**
     * Get the full name of the customer.
     *
     * @return ?string
     */
    public static function getAvCustomer(): self
    {
        return static::withoutGlobalScopes()
            ->where('type', CustomerType::AV->value)
            ->firstOrFail();
    }

    /**
     * Display / correspondence name: prefers the stored {@see $name} when non-empty (all customer types),
     * otherwise the composed personal name {@see $full_name}.
     */
    public function getName(): ?string
    {
        $storedName = trim((string)($this->name ?? ''));
        if ($storedName !== '') {
            return $storedName;
        }

        $personal = trim($this->full_name);

        return $personal !== '' ? $personal : null;
    }

    public function getVat(): ?string
    {
        return $this->vat;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function getKvk(): ?string
    {
        return $this->kvk;
    }

    public function getDescriptor(): string
    {
        if ($this->type !== CustomerType::B2C) {
            return $this->name ?? '-';
        }

        $name = trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));

        return $name !== '' ? $name : '-';
    }

    public function getAddress(): string
    {
        return $this->address?->getAddress() ?? '';
    }

    /**
     * Address used for Exact CRM Account visit fields (AddressLine1, Postcode, City, Country).
     * For business customers this follows the invoice (billing) address when present, matching Filament;
     * otherwise the primary contact address.
     */
    public function getExactAccountVisitAddress(): ?Address
    {
        $this->loadMissing([
            'billingAddress',
            'billingAddress.country',
            'address',
            'address.country',
        ]);

        if ($this->type?->isBusiness()) {
            return $this->billingAddress ?? $this->address;
        }

        return $this->address;
    }

    /**
     * Invoice-row contact name for tables: {@see Address::$name} on {@see $this->billingAddress} for business
     * (kept in sync from voornaam/tussenvoegsel/achternaam via {@see syncPersonDisplayNameOntoAddresses()}),
     * otherwise on {@see $this->address} (B2C factuur/contact in Filament). Falls back to {@see getDescriptor()}.
     */
    public function getInvoiceContactPersonForOverview(): string
    {
        $this->loadMissing(['billingAddress', 'address']);

        $name = $this->getInvoiceAddress()?->getName();
        if (is_string($name)) {
            $trimmed = trim($name);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $this->getDescriptor();
    }

    /**
     * Persist the contact person display name (first, middle, last) onto related addresses:
     * onto {@see $this->billingAddress}; when {@see Customer::$delivery_address_type} is custom, onto
     * {@see $this->shippingAddress} when distinct.
     * So {@see Address::getName()} matches CSV and invoice-style usage without a separate "Ter attentie van" field on the invoice form.
     */
    public function syncPersonDisplayNameOntoAddresses(): void
    {
        $fullName = trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));

        if ($fullName === '') {
            return;
        }

        $this->loadMissing(['billingAddress', 'shippingAddress']);

        $invoiceAddress = $this->billingAddress;
        if ($invoiceAddress !== null && $invoiceAddress->name !== $fullName) {
            $invoiceAddress->name = $fullName;
            $invoiceAddress->save();
        }

        if (($this->delivery_address_type ?? 'contact') !== 'custom') {
            return;
        }

        $shipping = $this->shippingAddress;
        if ($shipping === null || $invoiceAddress === null || $shipping->id === $invoiceAddress->id) {
            return;
        }

        if ($shipping->name !== $fullName) {
            $shipping->name = $fullName;
            $shipping->save();
        }
    }

    /**
     * Get the full personal name of the customer (first + middle + last).
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        return implode(' ',
            array_filter([
                $this->first_name,
                $this->middle_name,
                $this->last_name
            ])
        );
    }


    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return CustomerStatus
     */
    public function getStatus(): CustomerStatus
    {
        return $this->status;
    }

    /**
     * @param CustomerStatus $status
     * @return Customer
     */
    public function setStatus(CustomerStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return CustomerType|null
     */
    public function getType(): ?CustomerType
    {
        return $this->type;
    }

    /**
     * Whether saving this customer should queue a push to Exact Online (CRM account / debiteur).
     */
    public function shouldPushCustomerToExact(): bool
    {
        if ($this->status === CustomerStatus::Test) {
            return false;
        }

        return $this->getType() !== CustomerType::AV;
    }

    /**
     * @param CustomerType $type
     * @return Customer
     */
    public function setType(CustomerType $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @param string|null $email
     * @return Customer
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getSalutation(): ?string
    {
        return $this->salutation;
    }

    /**
     * @param string|null $salutation
     * @return Customer
     */
    public function setSalutation(?string $salutation): self
    {
        $this->salutation = $salutation;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getFirstName(): ?string
    {
        return $this->first_name;
    }

    /**
     * @param string|null $first_name
     * @return Customer
     */
    public function setFirstName(?string $first_name): self
    {
        $this->first_name = $first_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getMiddleName(): ?string
    {
        return $this->middle_name;
    }

    /**
     * @param string|null $middle_name
     * @return Customer
     */
    public function setMiddleName(?string $middle_name): self
    {
        $this->middle_name = $middle_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getLastName(): ?string
    {
        return $this->last_name;
    }

    /**
     * @param string|null $last_name
     * @return Customer
     */
    public function setLastName(?string $last_name): self
    {
        $this->last_name = $last_name;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStreet(): ?string
    {
        return $this->address?->street;
    }

    /**
     * @return string|null
     */
    public function getHouseNumber(): ?string
    {
        return $this->address?->house_number;
    }

    /**
     * @return string|null
     */
    public function getHouseNumberAddition(): ?string
    {
        return $this->address?->house_number_addition;
    }

    /**
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->address?->city;
    }

    /**
     * @return string|null
     */
    public function getPostcode(): ?string
    {
        return $this->address?->postcode;
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
     * @return Country|null
     */
    public function getCountry(): ?Country
    {
        return $this->address?->country;
    }

    /**
     * Get the country via address (for backward compatibility).
     */
    public function getCountryAttribute(): ?Country
    {
        return $this->address?->country;
    }

    /**
     * @return Region|null
     */
    public function getRegion(): ?Region
    {
        return $this->address?->region;
    }

    /**
     * Get the region via address (for backward compatibility).
     */
    public function getRegionAttribute(): ?Region
    {
        return $this->address?->region;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phone_number;
    }

    public function setPhoneNumber(?string $phone_number): void
    {
        $this->phone_number = $phone_number;
    }

    public function getMobilePhoneNumber(): ?string
    {
        return $this->mobile_phone_number;
    }

    public function setMobilePhoneNumber(?string $mobile_phone_number): void
    {
        $this->mobile_phone_number = $mobile_phone_number;
    }

    public function getAvailablePhoneNumber(): ?string
    {
        return $this->getMobilePhoneNumber() ?? $this->getPhoneNumber();
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getPaymentTerms(): ?PaymentTerms
    {
        return $this->payment_terms;
    }

    public function setPaymentTerms(PaymentTerms|string|null $paymentTerms): self
    {
        $this->payment_terms = $paymentTerms;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getReasonInactive(): ?string
    {
        return $this->reason_inactive;
    }

    /**
     * @param bool $reason_inactive
     * @return Customer
     */
    public function setReasonInactive(?string $reasonInactive): self
    {
        $this->reason_inactive = $reasonInactive;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getExactPaymentCondition(): ?string
    {
        return $this->exact_payment_condition;
    }

    /**
     * @param string|null $exactPaymentCondition
     * @return Customer
     */
    public function setExactPaymentCondition(?string $exactPaymentCondition): self
    {
        $this->exact_payment_condition = $exactPaymentCondition;

        return $this;
    }

    /**
     * Exact sales VAT code; falls back to {@see ExactVATCode::DEFAULT_SALES_VAT_CODE} when empty.
     */
    public function getExactVatCode(): string
    {
        $code = $this->exact_vat_code;

        if ($code !== null && trim((string) $code) !== '') {
            return (string) $code;
        }

        return ExactVATCode::DEFAULT_SALES_VAT_CODE;
    }

    /**
     * @param string|null $exactVatCode
     * @return Customer
     */
    public function setExactVatCode(?string $exactVatCode): self
    {
        $this->exact_vat_code = $exactVatCode;

        return $this;
    }

    public function getExactId(): ?string
    {
        return $this->exact_id;
    }

    public function setExactId(?string $exactId): self
    {
        $this->exact_id = $exactId;

        return $this;
    }

    public function getDebtorNumber(): ?string
    {
        return $this->debtor_number;
    }

    public function setDebtorNumber(?string $debtorNumber): self
    {
        $this->debtor_number = $debtorNumber;

        return $this;
    }

    /**
     * @return Carbon|null
     */
    public function getExactSyncedAt(): ?Carbon
    {
        return $this->exact_synced_at;
    }

    /**
     * @param Carbon|null $exactSyncedAt
     * @return Customer
     */
    public function setExactSyncedAt(?Carbon $exactSyncedAt): self
    {
        $this->exact_synced_at = $exactSyncedAt;

        return $this;
    }
}

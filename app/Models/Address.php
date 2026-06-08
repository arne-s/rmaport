<?php

namespace App\Models;

use App\Enums\AddressPurpose;
use App\Observers\AddressObserver;
use Barryvdh\LaravelIdeHelper\Eloquent;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 *
 *
 * @property int $id
 * @property int|null $customer_id
 * @property AddressPurpose|null $type
 * @property string|null $postcode
 * @property string|null $location_name
 * @property string|null $street
 * @property string|null $house_number
 * @property string|null $house_number_addition
 * @property string|null $city
 * @property int|null $country_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|Address newModelQuery()
 * @method static Builder|Address newQuery()
 * @method static Builder|Address query()
 * @method static Builder|Address whereCity($value)
 * @method static Builder|Address whereCountryId($value)
 * @method static Builder|Address whereCustomerId($value)
 * @method static Builder|Address whereCreatedAt($value)
 * @method static Builder|Address whereHouseNumber($value)
 * @method static Builder|Address whereHouseNumberAddition($value)
 * @method static Builder|Address whereType($value)
 * @method static Builder|Address whereId($value)
 * @method static Builder|Address wherePostcode($value)
 * @method static Builder|Address whereStreet($value)
 * @method static Builder|Address whereUpdatedAt($value)
 * @property-read Country|null $country
 * @property-read Customer|null $customer
 * @property string|null $additional
 * @method static Builder|Address whereAdditional($value)
 * @mixin Eloquent
 *
 */
#[ObservedBy([AddressObserver::class])]
class Address extends Model
{
    protected $attributes = [
        'newsletter_subscribed' => true,
    ];

    protected $casts = [
        'type' => AddressPurpose::class,
        'additional' => 'array',
        'newsletter_subscribed' => 'boolean',
    ];

    protected $fillable = [
        'postcode',
        'name',
        'location_name',
        'email',
        'phone_number',
        'mobile_phone_number',
        'newsletter_subscribed',
        'street',
        'house_number',
        'house_number_addition',
        'city',
        'additional',
        'country_id',
        'region_id',
        'comment',
        'type',
        'customer_id',
    ];

    public function getAddressTemplate(): string
    {
        return $this->getStreet() . ' ' . $this->getHouseNumber() .
            $this->getHouseNumberAddition() . ', ' .
            $this->getPostcode() . ', ' .
            $this->getCity();
    }

    public function getAddressTemplateIncName($isShipping = false, $name = ''): string
    {
        if ($isShipping) {
            $name = $this->getLocationName();
        }
        return $name . ', ' .
            $this->getStreet() . ' ' . $this->getHouseNumber() .
            $this->getHouseNumberAddition() . ', ' .
            $this->getPostcode() . ', ' .
            $this->getCity();
    }

    public function getAddressTemplateIncNameFormatted(): string
    {
        $name = $this->customer?->getName() ?? '';
        if ($this->getType() === AddressPurpose::Shipping) {
            $name = $this->getLocationName() ?? $name;
        }
        if ($name === '') {
            $name = $this->getName() ?? '';
        }

        return '<p>' . $name . '</p>' .
            '<p>' . $this->getStreet() . ' ' . $this->getHouseNumber() . $this->getHouseNumberAddition() . '</p>' .
            '<p>' . $this->getPostcode() . ', ' . $this->getCity() . '</p>' .
            '<p>' . $this->country?->getName() ?? 'Nederland'. '</p>';
    }

    public function getStreetTemplate(): string
    {
        return trim(implode(' ', [$this->getStreet(), $this->getHouseNumber(),
            $this->getHouseNumberAddition()]));
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Billing before shipping; legacy {@see Customer::$address_id} is treated as billing.
     */
    public static function inferPurposeForAddressId(int $addressId): ?AddressPurpose
    {
        if (Customer::query()->where('billing_address_id', $addressId)->exists()) {
            return AddressPurpose::Billing;
        }

        if (Customer::query()->where('shipping_address_id', $addressId)->exists()) {
            return AddressPurpose::Shipping;
        }

        if (Customer::query()->where('address_id', $addressId)->exists()) {
            return AddressPurpose::Billing;
        }

        return null;
    }

    /**
     * Fills {@see $type} once from customer FK links when still unset (no model events).
     */
    public function syncInferredTypeFromCustomerLinks(): void
    {
        $id = $this->getKey();
        if ($id === null) {
            return;
        }

        if ($this->type !== null) {
            return;
        }

        $inferred = self::inferPurposeForAddressId((int) $id);
        if ($inferred === null) {
            return;
        }

        $this->forceFill(['type' => $inferred])->saveQuietly();
    }

    /**
     * Copy from source to a new Address (replicate) or create an empty Address. Saves and returns the new instance.
     */
    public static function copyFrom(?Address $source): Address
    {
        if ($source === null) {
            $address = new self();
            $address->type = null;
            $address->customer_id = null;
            $address->save();

            return $address;
        }

        $address = $source->replicate();
        $address->customer_id = null;
        $address->type = null;
        $address->save();

        return $address;
    }

    public function getAddress(): string
    {
        return trim(implode(' ', [
            $this->getStreet() ?? '',
            $this->getHouseNumber() ?? '',
            $this->getHouseNumberAddition() ?? '']));
    }

    public function getCountryCode(): string
    {
        return strtoupper($this->country->code) ?? '';
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
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getType(): ?AddressPurpose
    {
        $value = $this->type;

        if ($value instanceof AddressPurpose) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return AddressPurpose::tryFrom((string) $value);
    }

    public function setType(AddressPurpose|string|null $value): void
    {
        $this->type = $value;
    }

    public function getCustomerId(): ?int
    {
        return $this->customer_id !== null ? (int) $this->customer_id : null;
    }

    public function setCustomerId(?int $customerId): void
    {
        $this->customer_id = $customerId;
    }

    /**
     * @return string|null
     */
    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    /**
     * @param string|null $postcode
     */
    public function setPostcode(?string $postcode): void
    {
        $this->postcode = $postcode;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getLocationName(): ?string
    {
        return $this->location_name;
    }

    public function setLocationName(?string $locationName): void
    {
        $this->location_name = $locationName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    /**
     * @return string|null
     */
    public function getStreet(): ?string
    {
        return $this->street;
    }

    /**
     * @param string|null $street
     */
    public function setStreet(?string $street): void
    {
        $this->street = $street;
    }

    /**
     * @return string|null
     */
    public function getHouseNumber(): ?string
    {
        return $this->house_number;
    }

    /**
     * @param string|null $house_number
     */
    public function setHouseNumber(?string $house_number): void
    {
        $this->house_number = $house_number;
    }

    /**
     * @return string|null
     */
    public function getHouseNumberAddition(): ?string
    {
        return $this->house_number_addition;
    }

    /**
     * @param string|null $house_number_addition
     */
    public function setHouseNumberAddition(?string $house_number_addition): void
    {
        $this->house_number_addition = $house_number_addition;
    }

    /**
     * @return string|null
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * @param string|null $city
     */
    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    /**
     * @return int|null
     */
    public function getCountryId(): ?int
    {
        return $this->country_id;
    }

    /**
     * @param int|null $country_id
     */
    public function setCountryId(?int $country_id): void
    {
        $this->country_id = $country_id;
    }

    /**
     * @return string
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    /**
     * @param string $comment
     */
    public function setComment(string $comment): void
    {
        $this->comment = $comment;
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
    public function getAdditional(): ?string
    {
        return $this->additional;
    }

    /**
     * @param string|null $additional
     */
    public function setAdditional(?string $additional): void
    {
        $this->additional = $additional;
    }

}

<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\Region
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $country_id
 * @property-read Country $country
 * @method static Builder|Region newModelQuery()
 * @method static Builder|Region newQuery()
 * @method static Builder|Region query()
 * @method static Builder|Region whereCode($value)
 * @method static Builder|Region whereCountryId($value)
 * @method static Builder|Region whereId($value)
 * @method static Builder|Region whereName($value)
 * @mixin Eloquent
 */
class Region extends Model
{
    public $timestamps = false;

    protected $table = 'regions';

    protected $fillable = [
        'name',
        'code',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
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
     * @return Region
     */
    public function setName(string $name): Region
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @param string $code
     * @return Region
     */
    public function setCode(string $code): Region
    {
        $this->code = $code;
        return $this;
    }

    /**
     * @return int
     */
    public function getCountryId(): int
    {
        return $this->country_id;
    }

    /**
     * @param int $country_id
     * @return Region
     */
    public function setCountryId(int $country_id): Region
    {
        $this->country_id = $country_id;
        return $this;
    }

    /**
     * @return Country
     */
    public function getCountry(): Country
    {
        return $this->country;
    }

    /**
     * @param Country $country
     * @return Region
     */
    public function setCountry(Country $country): Region
    {
        $this->country = $country;
        return $this;
    }
}

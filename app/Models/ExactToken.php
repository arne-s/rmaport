<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\ExactToken
 *
 * @property int $id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|ExactToken newModelQuery()
 * @method static Builder|ExactToken newQuery()
 * @method static Builder|ExactToken query()
 * @method static Builder|ExactToken whereAccessToken($value)
 * @method static Builder|ExactToken whereCreatedAt($value)
 * @method static Builder|ExactToken whereExpiresAt($value)
 * @method static Builder|ExactToken whereId($value)
 * @method static Builder|ExactToken whereRefreshToken($value)
 * @method static Builder|ExactToken whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class ExactToken extends Model
{
    use HasFactory;

    public $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return ExactToken
     */
    public function setId(int $id): ExactToken
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        return $this->access_token;
    }

    /**
     * @param string|null $access_token
     * @return ExactToken
     */
    public function setAccessToken(?string $access_token): ExactToken
    {
        $this->access_token = $access_token;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        return $this->refresh_token;
    }

    /**
     * @param string|null $refresh_token
     * @return ExactToken
     */
    public function setRefreshToken(?string $refresh_token): ExactToken
    {
        $this->refresh_token = $refresh_token;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        return $this->expires_at;
    }

    /**
     * @param string|null $expires_at
     * @return ExactToken
     */
    public function setExpiresAt(?string $expires_at): ExactToken
    {
        $this->expires_at = $expires_at;
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

}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $microsoft_token_id
 * @property string $category_name
 * @property string|null $outlook_category_id
 * @property string|null $category_color
 * @property int|null $user_id
 * @property-read MicrosoftToken $microsoftToken
 * @property-read User|null $user
 */
class MicrosoftCategoryMapping extends Model
{
    protected $fillable = [
        'microsoft_token_id',
        'category_name',
        'outlook_category_id',
        'category_color',
        'hex_color',
        'user_id',
    ];

    public function microsoftToken(): BelongsTo
    {
        return $this->belongsTo(MicrosoftToken::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

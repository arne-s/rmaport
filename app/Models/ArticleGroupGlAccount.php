<?php

namespace App\Models;

use App\Enums\ArticleGroupGlAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $exact_article_group_id
 * @property int $exact_gl_account_id
 * @property ArticleGroupGlAccountType $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ExactArticleGroup $articleGroup
 * @property-read ExactGLAccount $glAccount
 */
class ArticleGroupGlAccount extends Model
{
    protected $table = 'exact_article_group_gl_account';

    protected $fillable = [
        'exact_article_group_id',
        'exact_gl_account_id',
        'type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArticleGroupGlAccountType::class,
        ];
    }

    public function articleGroup(): BelongsTo
    {
        return $this->belongsTo(ExactArticleGroup::class, 'exact_article_group_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ExactGLAccount::class, 'exact_gl_account_id');
    }
}

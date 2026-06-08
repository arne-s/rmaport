<?php

namespace App\Models;

use App\Enums\ArticleGroupGlAccountType;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\ExactArticleGroup
 *
 * @property int $id
 * @property string $name
 * @property string $guid
 * @property string $vat_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ArticleGroupGlAccount> $glAccountLinks
 * @method static Builder|ExactArticleGroup newModelQuery()
 * @method static Builder|ExactArticleGroup newQuery()
 * @method static Builder|ExactArticleGroup query()
 * @method static Builder|ExactArticleGroup whereGuid($value)
 * @method static Builder|ExactArticleGroup whereId($value)
 * @method static Builder|ExactArticleGroup whereName($value)
 * @method static Builder|ExactArticleGroup whereVatCode($value)
 * @mixin Eloquent
 */
class ExactArticleGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'guid',
        'vat_code',
    ];

    public function glAccountLinks(): HasMany
    {
        return $this->hasMany(ArticleGroupGlAccount::class, 'exact_article_group_id');
    }

    public function getGlAccountByType(ArticleGroupGlAccountType $type): ?ExactGLAccount
    {
        $link = $this->glAccountLinks->firstWhere('type', $type);

        return $link?->glAccount;
    }

    public function getRevenueGlAccount(): ?ExactGLAccount
    {
        return $this->getGlAccountByType(ArticleGroupGlAccountType::Revenue);
    }

    /**
     * Formatted label for a given GL account type, e.g. "8482 - Omzet E-motion / E-Fix".
     */
    public function getGlAccountLabel(ArticleGroupGlAccountType $type): ?string
    {
        $glAccount = $this->getGlAccountByType($type);

        if ($glAccount === null) {
            return null;
        }

        return $glAccount->code.' - '.$glAccount->name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): ExactArticleGroup
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): ExactArticleGroup
    {
        $this->name = $name;
        return $this;
    }

    public function getGuid(): string
    {
        return $this->guid;
    }

    public function setGuid(string $guid): ExactArticleGroup
    {
        $this->guid = $guid;
        return $this;
    }

    public function getVatCode(): string
    {
        return $this->vat_code;
    }

    public function setVatCode(string $vat_code): ExactArticleGroup
    {
        $this->vat_code = $vat_code;
        return $this;
    }

    public function getCreatedAt(): ?Carbon
    {
        return $this->created_at;
    }

    public function getUpdatedAt(): ?Carbon
    {
        return $this->updated_at;
    }
}

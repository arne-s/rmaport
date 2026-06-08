<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExactGLAccount extends Model
{
    use HasFactory;

    const TYPE_REVENUE = 110; // Revenue / Omzet
    const TYPE_COST_OF_GOODS = 111; // Cost of goods sold (standard GL type in Exact)

    /**
     * Exact GL types used as {@see Supplier::$exact_gl_account_id} / GLAccountPurchase in this administration.
     * Type 111 alone is too narrow (e.g. Wolturnus 7701 is type 90 in Exact).
     *
     * @var list<int>
     */
    public const PURCHASE_SUPPLIER_SELECT_TYPES = [
        90,
        111,
        120,
        30,
        125,
        130,
        110,
    ];

    protected $table = 'exact_gl_accounts';

    protected $fillable = [
        'code',
        'name',
        'guid',
        'type',
        'balance_side',
        'vat_code',
        'timestamp',
    ];

    public static function getCostOfGoodsAccounts()
    {
        return self::where('type', self::TYPE_COST_OF_GOODS)->get();
    }

    public static function getRevenueAccounts()
    {
        return self::where('type', self::TYPE_REVENUE)->get();
    }

    /**
     * @param  Builder<ExactGLAccount>  $query
     * @return Builder<ExactGLAccount>
     */
    public function scopeForSupplierPurchaseSelect(Builder $query, ?int $includeId = null): Builder
    {
        return $query
            ->where(function (Builder $innerQuery) use ($includeId): void {
                $innerQuery->whereIn('type', self::PURCHASE_SUPPLIER_SELECT_TYPES);

                if ($includeId !== null) {
                    $innerQuery->orWhere('id', $includeId);
                }
            })
            ->orderBy('code');
    }

    public static function convertToOptions($accounts)
    {
        return $accounts
            ->sortBy('code')
            ->mapWithKeys(fn ($account) => [
                $account->guid => "{$account->code} : {$account->name}",
            ])->toArray();
    }

    public static function getCostOfGoodsAccountsAsOptions()
    {
        $accounts = self::getCostOfGoodsAccounts();
        return self::convertToOptions($accounts);
    }

    public static function getRevenueAccountsAsOptions()
    {
        $accounts = self::getRevenueAccounts();
        return self::convertToOptions($accounts);
    }
}
<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * App\Models\TransactionalActionLog
 *
 * @property int $id
 * @property string $class
 * @property string $key
 * @property string $status
 * @property int|null $user_id
 * @property string|null $error_message
 * @property Carbon|null $executed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog query()
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereClass($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereExecutedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalActionLog whereUserId($value)
 * @mixin Eloquent
 */
class TransactionalActionLog extends Model
{
    protected $table = 'transactional_action_logs';

    protected $fillable = [
        'class',
        'key',
        'status',
        'user_id',
        'error_message',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who executed the action.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


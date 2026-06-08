<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App\Models\TransactionalAction
 *
 * @property int $id
 * @property string $class
 * @property string $key
 * @property Carbon $executed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction query()
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction whereClass($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction whereExecutedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|TransactionalAction whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class TransactionalAction extends Model
{
    protected $table = 'transactional_actions';

    protected $fillable = [
        'class',
        'key',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Get the key string.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Get the class name.
     *
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Get the executed at timestamp.
     *
     * @return Carbon|null
     */
    public function getExecutedAt(): ?Carbon
    {
        return $this->executed_at;
    }
}


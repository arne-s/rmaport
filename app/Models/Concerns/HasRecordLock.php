<?php

namespace App\Models\Concerns;

use App\Models\RecordLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @mixin Model
 */
trait HasRecordLock
{
    /**
     * @return MorphOne<RecordLock, $this>
     */
    public function recordLock(): MorphOne
    {
        return $this->morphOne(RecordLock::class, 'lockable');
    }

    /**
     * @return MorphOne<RecordLock, $this>
     */
    public function activeRecordLock(): MorphOne
    {
        return $this->recordLock()->where('expires_at', '>', now());
    }
}

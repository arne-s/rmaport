<?php

namespace App\Models;

use App\Models\Order\BaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialNumberEvent extends Model
{
    protected $fillable = [
        'type',
        'data',
        'user_id',
        'serial_number_id',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function serialNumber(): BelongsTo
    {
        return $this->belongsTo(SerialNumber::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExactRequest extends Model
{
    protected $fillable = [
        'direction',
        'service',
        'method',
        'endpoint',
        'url',
        'request_headers',
        'request_body',
        'response_status',
        'response_headers',
        'response_body',
        'duration_ms',
        'succeeded',
        'error_class',
        'error_message',
        'correlation_id',
        'requested_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'response_headers' => 'array',
            'succeeded' => 'boolean',
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}

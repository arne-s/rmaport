<?php

namespace App\Models;

use App\Enums\AppointmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $appointment_type
 * @property int|null $microsoft_token_id
 * @property string|null $display_name
 * @property-read MicrosoftToken|null $token
 */
class MicrosoftAppointmentTypeToken extends Model
{
    protected $fillable = [
        'appointment_type',
        'microsoft_token_id',
        'display_name',
    ];

    protected function casts(): array
    {
        return [
            'appointment_type' => AppointmentType::class,
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(MicrosoftToken::class, 'microsoft_token_id');
    }
}

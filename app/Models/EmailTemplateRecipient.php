<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplateRecipient extends Model
{
    public const TYPE_TO = 'to';

    public const TYPE_CC = 'cc';

    public const TYPE_BCC = 'bcc';

    protected $fillable = [
        'email_template_id',
        'user_id',
        'type',
    ];

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OauthToken extends Model
{
    protected $table = 'oauth_tokens';

    protected $fillable = [
        'account_id',
        'provider',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'token_type',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}

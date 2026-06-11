<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormImportConnection extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'username',
        'api_token',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function formImports(): HasMany
    {
        return $this->hasMany(FormImport::class);
    }

    public function normalizedBaseUrl(): string
    {
        $url = rtrim(trim($this->base_url), '/');

        // Allow pasting REST URLs; store only the site root.
        $url = preg_replace('#/wp-json(?:/gf/v2)?/?$#', '', $url) ?? $url;
        $url = preg_replace('#/wp-admin/?$#', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    public function apiBaseUrl(): string
    {
        return $this->normalizedBaseUrl().'/wp-json/gf/v2/';
    }
}

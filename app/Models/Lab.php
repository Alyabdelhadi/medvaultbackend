<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Lab extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'slides' => 'array',
            // Facility credentials. Never serialised to the app.
            'api_headers' => 'encrypted',
            'api_enabled' => 'boolean',
            'api_timeout' => 'integer',
            'api_priority' => 'integer',
            'caps_ranges' => 'boolean',
            'caps_flags' => 'boolean',
            'caps_pdf' => 'boolean',
            'ai_summary' => 'boolean',
        ];
    }

    protected $hidden = ['api_headers'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'publish');
    }
}

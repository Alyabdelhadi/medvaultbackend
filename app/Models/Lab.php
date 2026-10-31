<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
            'radiology_enabled' => 'boolean',
            'orders_enabled' => 'boolean',
        ];
    }

    protected $hidden = ['api_headers'];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'publish');
    }

    public function plan(): HasOne
    {
        return $this->hasOne(LabPlan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LabPayment::class);
    }
}

<?php

namespace App\Models;

use App\Casts\JsonMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's standing at one lab. lab_id 0 is the legacy single-lab account.
 *
 * seen_status null means "not yet seeded"; an empty array does not. See the
 * lab_accounts migration before changing how either is written.
 */
class LabAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['external_token', 'radiology_token'];

    protected function casts(): array
    {
        return [
            'external_token' => 'encrypted',
            'auth_failures' => 'integer',
            // A second host's token, for a lab whose radiology has its own server.
            'radiology_token' => 'encrypted',
            'radiology_auth_failures' => 'integer',
            'seen_status' => JsonMap::class,
            'radiology_seen_status' => JsonMap::class,
            'polled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

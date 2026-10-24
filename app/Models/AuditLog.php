<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing someone did. Written by App\Services\Audit\AuditTrail only. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'lab_id' => 'integer',
        ];
    }
}

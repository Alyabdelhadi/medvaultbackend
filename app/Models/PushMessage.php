<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A notification sent from the admin: to everyone, a lab's patients or one patient. */
class PushMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lab_id' => 'integer',
            'recipients' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }
}

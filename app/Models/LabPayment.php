<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabPayment extends Model
{
    public const METHODS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lab_id' => 'integer',
            'amount' => 'decimal:2',
            'paid_on' => 'immutable_date',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
        ];
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

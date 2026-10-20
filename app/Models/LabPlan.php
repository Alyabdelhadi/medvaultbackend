<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a lab pays us, how often, and when the next payment is due. */
class LabPlan extends Model
{
    public const CYCLES = ['monthly' => 'Monthly', 'yearly' => 'Yearly'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lab_id' => 'integer',
            'amount' => 'decimal:2',
            'starts_on' => 'immutable_date',
            'next_due_on' => 'immutable_date',
            'remind_days' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** What this plan brings in over a year. */
    public function annualValue(): float
    {
        return (float) $this->amount * ($this->cycle === 'yearly' ? 1 : 12);
    }

    /** The due date one billing period after $from. */
    public function advance(CarbonImmutable $from): CarbonImmutable
    {
        return $this->cycle === 'yearly' ? $from->addYearNoOverflow() : $from->addMonthNoOverflow();
    }

    /** overdue | due (inside the reminder window) | ok | paused */
    public function state(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();
        if (! $this->active) {
            return 'paused';
        }
        if ($this->next_due_on->lt($today)) {
            return 'overdue';
        }

        return $this->next_due_on->subDays($this->remind_days)->lte($today) ? 'due' : 'ok';
    }

    /** Active plans whose reminder window has opened, overdue ones included. */
    public static function needingAttention(?CarbonImmutable $today = null)
    {
        $today ??= CarbonImmutable::today();

        return self::with('lab:id,title')->where('active', true)->orderBy('next_due_on')->get()
            ->filter(fn (self $plan) => in_array($plan->state($today), ['overdue', 'due'], true))
            ->values();
    }
}

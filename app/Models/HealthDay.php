<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** One awareness day, sent on its date every year. See the create_health_days migration. */
class HealthDay extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
            'sponsor_lab_id' => 'integer',
            'is_active' => 'boolean',
            'last_sent_en' => 'date',
            'last_sent_ar' => 'date',
        ];
    }

    /** The next date it goes out, today included, in Beirut. 29 February rolls on to the next leap year. */
    public function nextDate(?CarbonImmutable $today = null): CarbonImmutable
    {
        $today ??= CarbonImmutable::now(\App\Services\HealthDays\HealthDaySender::TZ)->startOfDay();
        for ($year = $today->year; $year <= $today->year + 4; $year++) {
            if (checkdate($this->month, $this->day, $year)) {
                $date = CarbonImmutable::create($year, $this->month, $this->day, 0, 0, 0, \App\Services\HealthDays\HealthDaySender::TZ);
                if ($date->greaterThanOrEqualTo($today)) {
                    return $date;
                }
            }
        }

        return $today;
    }
}

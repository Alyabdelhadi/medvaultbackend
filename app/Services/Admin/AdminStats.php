<?php

namespace App\Services\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Month-by-month series for the dashboard graphs, built in PHP so they read the same on every database. */
final class AdminStats
{
    /**
     * Counts (or sums of $value) per month for the last $months months,
     * oldest first, labelled "Jan", "Feb", ...
     *
     * @param  Collection<int, mixed>  $rows
     * @return array<string, float|int>
     */
    public static function lastMonths(Collection $rows, string $dateField, int $months = 12, ?string $value = null): array
    {
        $end = CarbonImmutable::now()->startOfMonth();
        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $series[$end->subMonthsNoOverflow($i)->format('Y-m')] = 0;
        }

        foreach ($rows as $row) {
            $date = data_get($row, $dateField);
            $key = $date ? CarbonImmutable::parse($date)->format('Y-m') : null;
            if ($key !== null && array_key_exists($key, $series)) {
                $series[$key] += $value === null ? 1 : (float) data_get($row, $value);
            }
        }

        return self::label($series);
    }

    /**
     * Sums of $value per calendar month of $year.
     *
     * @return array<string, float>
     */
    public static function monthsOfYear(Collection $rows, string $dateField, string $value, int $year): array
    {
        $series = [];
        for ($m = 1; $m <= 12; $m++) {
            $series[sprintf('%04d-%02d', $year, $m)] = 0.0;
        }
        foreach ($rows as $row) {
            $key = CarbonImmutable::parse(data_get($row, $dateField))->format('Y-m');
            if (array_key_exists($key, $series)) {
                $series[$key] += (float) data_get($row, $value);
            }
        }

        return self::label($series);
    }

    private static function label(array $series): array
    {
        $out = [];
        foreach ($series as $ym => $n) {
            $out[CarbonImmutable::createFromFormat('!Y-m', $ym)->format('M')] = $n;
        }

        return $out;
    }
}

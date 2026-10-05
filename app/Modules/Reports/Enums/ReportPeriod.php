<?php

namespace App\Modules\Reports\Enums;

use Carbon\CarbonImmutable;

/**
 * The windows offered by the dashboard. Every boundary is computed in the
 * store's own timezone (a "day" is the shopkeeper's day, not UTC's) — see
 * app/Modules/Reports/README.md §"Périodes".
 */
enum ReportPeriod: string
{
    case Today = 'aujourdhui';
    case Last7Days = '7_jours';
    case Last30Days = '30_jours';
    case ThisMonth = 'ce_mois';

    public function label(): string
    {
        return match ($this) {
            self::Today => "Aujourd'hui",
            self::Last7Days => '7 derniers jours',
            self::Last30Days => '30 derniers jours',
            self::ThisMonth => 'Ce mois-ci',
        };
    }

    /** Hourly buckets for a single day, daily buckets otherwise. */
    public function isHourly(): bool
    {
        return $this === self::Today;
    }

    /**
     * Current window: from its start up to `$now` (never the future —
     * "today" stops at the current minute).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(CarbonImmutable $now): array
    {
        $start = match ($this) {
            self::Today => $now->startOfDay(),
            self::Last7Days => $now->subDays(6)->startOfDay(),
            self::Last30Days => $now->subDays(29)->startOfDay(),
            self::ThisMonth => $now->startOfMonth(),
        };

        return [$start, $now];
    }

    /**
     * The window to compare against, with the SAME elapsed duration: at
     * 10:00, "today" is compared with yesterday 00:00–10:00, not with the
     * whole of yesterday — otherwise every morning would look like a drop.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousRange(CarbonImmutable $now): array
    {
        [$start, $end] = $this->range($now);

        $previousStart = match ($this) {
            self::Today => $start->subDay(),
            self::Last7Days => $start->subDays(7),
            self::Last30Days => $start->subDays(30),
            self::ThisMonth => $start->subMonthNoOverflow(),
        };

        $previousEnd = $previousStart->addSeconds((int) $start->diffInSeconds($end));

        if ($this === self::ThisMonth && $previousEnd->greaterThan($previousStart->endOfMonth())) {
            $previousEnd = $previousStart->endOfMonth();
        }

        return [$previousStart, $previousEnd];
    }
}

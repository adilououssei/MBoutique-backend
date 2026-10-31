<?php

namespace App\Modules\Reports\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The windows offered by the dashboard. Every boundary is computed in the
 * store's own timezone (a "day" is the shopkeeper's day, not UTC's) — see
 * app/Modules/Reports/README.md §"Périodes".
 */
enum ReportPeriod: string
{
    case Today = 'aujourdhui';
    case ThisWeek = 'cette_semaine';
    case Last7Days = '7_jours';
    case Last30Days = '30_jours';
    case ThisMonth = 'ce_mois';
    case ThisYear = 'cette_annee';
    /** Du … au … (dates locales) passées à part : `range($now, [du, au])`. */
    case Custom = 'personnalisee';

    public function label(): string
    {
        return match ($this) {
            self::Today => "Aujourd'hui",
            self::ThisWeek => 'Cette semaine',
            self::Last7Days => '7 derniers jours',
            self::Last30Days => '30 derniers jours',
            self::ThisMonth => 'Ce mois-ci',
            self::ThisYear => 'Cette année',
            self::Custom => 'Période personnalisée',
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
    public function range(CarbonImmutable $now, ?array $custom = null): array
    {
        if ($this === self::Custom) {
            [$from, $to] = $custom ?? [$now->toDateString(), $now->toDateString()];
            $start = CarbonImmutable::parse($from, $now->getTimezone())->startOfDay();
            $end = CarbonImmutable::parse($to, $now->getTimezone())->endOfDay();

            return [$start, $end->greaterThan($now) ? $now : $end];
        }
        $start = match ($this) {
            self::Today => $now->startOfDay(),
            self::ThisWeek => $now->startOfWeek(CarbonInterface::MONDAY),
            self::Last7Days => $now->subDays(6)->startOfDay(),
            self::Last30Days => $now->subDays(29)->startOfDay(),
            self::ThisMonth => $now->startOfMonth(),
            self::ThisYear => $now->startOfYear(),
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
    public function previousRange(CarbonImmutable $now, ?array $custom = null): array
    {
        [$start, $end] = $this->range($now, $custom);
        if ($this === self::Custom) {
            // Même durée, juste avant.
            $length = (int) $start->diffInSeconds($end);

            return [$start->subSeconds($length + 1), $start->subSecond()];
        }

        $previousStart = match ($this) {
            self::Today => $start->subDay(),
            self::ThisWeek => $start->subWeek(),
            self::Last7Days => $start->subDays(7),
            self::Last30Days => $start->subDays(30),
            self::ThisMonth => $start->subMonthNoOverflow(),
            self::ThisYear => $start->subYear(),
        };

        $previousEnd = $previousStart->addSeconds((int) $start->diffInSeconds($end));

        if ($this === self::ThisMonth && $previousEnd->greaterThan($previousStart->endOfMonth())) {
            $previousEnd = $previousStart->endOfMonth();
        }

        return [$previousStart, $previousEnd];
    }
}

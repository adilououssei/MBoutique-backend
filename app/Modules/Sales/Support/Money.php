<?php

namespace App\Modules\Sales\Support;

/**
 * bcmath's own functions TRUNCATE at the given scale, they don't round
 * — `bcmul('10.33', '1.5', 2)` truncates 15.495 down to "15.49" instead
 * of rounding to "15.50". round()/number_format() would reintroduce a
 * float round-trip, which the rest of the project avoids for money. This
 * is the standard bcmath round-half-up trick instead: add half a cent,
 * then truncate — see docs/sales.md §"Arrondi".
 */
final class Money
{
    public static function round(string $value, int $scale = 2): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');

        $halfUnit = '0.'.str_repeat('0', $scale).'5'; // e.g. "0.005" for scale=2
        $rounded = bcadd($absolute, $halfUnit, $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? "-{$rounded}" : $rounded;
    }
}

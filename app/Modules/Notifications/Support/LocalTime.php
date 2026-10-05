<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonInterface;

/** Heures des messages à l'heure de la boutique (`boutiques.fuseau_horaire`), jamais en UTC brut. */
final class LocalTime
{
    public static function format(CarbonInterface $instant, int $storeId, string $format = 'H:i'): string
    {
        $timezone = Store::query()->whereKey($storeId)->value('fuseau_horaire') ?: config('app.timezone');

        return $instant->copy()->setTimezone($timezone)->format($format);
    }

    /** « aujourd'hui à 09:00 », « demain à 14:30 » ou « le 12/10 à 10:00 ». */
    public static function when(CarbonInterface $instant, int $storeId): string
    {
        $timezone = Store::query()->whereKey($storeId)->value('fuseau_horaire') ?: config('app.timezone');
        $local = $instant->copy()->setTimezone($timezone);
        $today = now($timezone)->startOfDay();

        $day = match (true) {
            $local->isSameDay($today) => "aujourd'hui",
            $local->isSameDay($today->copy()->addDay()) => 'demain',
            default => 'le '.$local->format('d/m'),
        };

        return "{$day} à {$local->format('H:i')}";
    }
}

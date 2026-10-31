<?php

namespace App\Modules\Reports\Support;

use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;

/**
 * Petits outils communs aux rapports (requêtes query-builder filtrées à la
 * main sur boutique_id) : fuseau de la boutique, bornes en UTC, nombres
 * renvoyés différemment par MySQL et SQLite.
 */
trait QueriesStoreData
{
    /** Les dates sont stockées dans le fuseau de l'application (UTC). */
    private function utc(CarbonImmutable $moment): string
    {
        return $moment->setTimezone('UTC')->format('Y-m-d H:i:s');
    }

    private function timezoneOf(Store $store): string
    {
        $timezone = (string) ($store->fuseau_horaire ?: 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * SUM() comes back as a string on MySQL but as an int/float on
     * SQLite; bcmath needs a plain numeric string either way.
     */
    private function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return is_float($value) ? number_format($value, 4, '.', '') : (string) $value;
    }

    private function quantity(string $value): string
    {
        return bcadd($value, '0', 3);
    }
}

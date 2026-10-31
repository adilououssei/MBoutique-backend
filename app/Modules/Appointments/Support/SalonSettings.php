<?php

namespace App\Modules\Appointments\Support;

use App\Modules\Tenancy\Models\Store;

/**
 * Réglages du salon dans boutiques.parametres : horaires d'ouverture par
 * jour (1 = lundi … 7 = dimanche, null = fermé) et barème de fidélité.
 * Non renseignés : aucune contrainte d'horaire, fidélité sans effet.
 */
class SalonSettings
{
    /** @return array<int, array{ouvre: string, ferme: string}|null>|null null = horaires non configurés */
    public static function openingHours(Store $store): ?array
    {
        $hours = $store->parametres['horaires_ouverture'] ?? null;
        if (! is_array($hours) && ! $hours instanceof \ArrayAccess) {
            return null;
        }
        $result = [];
        for ($day = 1; $day <= 7; $day++) {
            $slot = $hours[$day] ?? $hours[(string) $day] ?? null;
            $result[$day] = is_array($slot) || $slot instanceof \ArrayAccess ? ['ouvre' => (string) $slot['ouvre'], 'ferme' => (string) $slot['ferme']] : null;
        }

        return $result;
    }

    /** @return array{montant_par_point: string, valeur_point: string}|null null = fidélité non paramétrée */
    public static function loyalty(Store $store): ?array
    {
        $loyalty = $store->parametres['fidelite'] ?? null;
        if ($loyalty === null || empty($loyalty['montant_par_point']) || empty($loyalty['valeur_point'])) {
            return null;
        }

        return ['montant_par_point' => (string) $loyalty['montant_par_point'], 'valeur_point' => (string) $loyalty['valeur_point']];
    }

    public static function timezone(Store $store): string
    {
        return $store->fuseau_horaire ?: 'UTC';
    }
}

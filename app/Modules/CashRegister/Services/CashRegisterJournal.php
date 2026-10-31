<?php

namespace App\Modules\CashRegister\Services;

use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ventes terminées d'une caisse, totalisées par jour dans le fuseau de la
 * boutique (découpage en PHP : DATE() SQL ne connaît pas ce fuseau).
 * Lecture seule. Espèces : montant_especes, ou pour les ventes d'avant ce
 * champ l'acompte / le total (même règle que l'annulation).
 */
class CashRegisterJournal
{
    /**
     * @return array{
     *     aujourdhui: array{date: string, nombre_ventes: int, chiffre_affaires: string, especes: string, credit: string},
     *     jours: array<int, array{date: string, nombre_ventes: int, chiffre_affaires: string, especes: string, credit: string}>
     * }
     */
    public function build(Store $store, CashRegister $register, int $days, ?CarbonImmutable $now = null): array
    {
        $timezone = in_array((string) $store->fuseau_horaire, timezone_identifiers_list(), true) ? (string) $store->fuseau_horaire : 'UTC';
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $from = $now->startOfDay()->subDays($days - 1);

        $rows = DB::table('ventes')
            ->where('boutique_id', $store->id)
            ->where('caisse_id', $register->id)
            ->where('statut', SaleStatus::Completed->value)
            ->where('vendue_le', '>=', $from->setTimezone('UTC')->format('Y-m-d H:i:s'))
            ->get(['vendue_le', 'montant_total', 'montant_acompte', 'montant_especes']);

        $buckets = [];
        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->vendue_le, 'UTC')->setTimezone($timezone)->format('Y-m-d');
            $bucket = $buckets[$day] ?? ['date' => $day, 'nombre_ventes' => 0, 'chiffre_affaires' => '0', 'especes' => '0', 'credit' => '0'];
            $total = (string) $row->montant_total;
            $cash = (string) ($row->montant_especes ?? $row->montant_acompte ?? $row->montant_total);
            $credit = $row->montant_acompte !== null ? bcsub($total, (string) $row->montant_acompte, 2) : '0';

            $bucket['nombre_ventes']++;
            $bucket['chiffre_affaires'] = bcadd($bucket['chiffre_affaires'], $total, 2);
            $bucket['especes'] = bcadd($bucket['especes'], $cash, 2);
            $bucket['credit'] = bcadd($bucket['credit'], $credit, 2);
            $buckets[$day] = $bucket;
        }

        $format = fn (array $b) => [...$b, 'chiffre_affaires' => Money::round($b['chiffre_affaires']), 'especes' => Money::round($b['especes']), 'credit' => Money::round($b['credit'])];
        $today = $now->format('Y-m-d');
        krsort($buckets);

        return [
            'aujourdhui' => $format($buckets[$today] ?? ['date' => $today, 'nombre_ventes' => 0, 'chiffre_affaires' => '0', 'especes' => '0', 'credit' => '0']),
            'jours' => array_values(array_map($format, array_filter($buckets, fn (array $b) => $b['date'] !== $today))),
        ];
    }
}

<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Enums\ReportPeriod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregates over Sales for the dashboard. Never writes
 * anything (docs/modules.md, Reports rule 5). Only `terminee` sales count:
 * a cancelled sale is not revenue. Every query is filtered on
 * boutique_id explicitly — these are query-builder queries, so the
 * BelongsToStore global scope does not apply. See app/Modules/Reports/README.md.
 */
class DashboardReportService
{
    private const TOP_PRODUCTS_LIMIT = 5;

    /**
     * @return array{
     *     periode: array{code: string, libelle: string, du: string, au: string, fuseau_horaire: string},
     *     resume: array<string, mixed>,
     *     periode_precedente: array<string, mixed>,
     *     evolution: array<string, float|null>,
     *     courbe: array{granularite: string, points: array<int, array{cle: string, libelle: string, chiffre_affaires: string, nombre_ventes: int}>},
     *     meilleurs_produits: array<int, array{produit_id: int, nom: string, quantite: string, chiffre_affaires: string}>,
     *     modes_paiement: array<int, array{mode: string, montant: string, nombre_ventes: int}>
     * }
     */
    public function build(Store $store, ReportPeriod $period, ?CarbonImmutable $now = null): array
    {
        $timezone = $this->timezoneOf($store);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);

        [$start, $end] = $period->range($now);
        [$previousStart, $previousEnd] = $period->previousRange($now);

        $summary = $this->summary($store, $start, $end);
        $previousSummary = $this->summary($store, $previousStart, $previousEnd);

        return [
            'periode' => [
                'code' => $period->value,
                'libelle' => $period->label(),
                'du' => $start->toIso8601String(),
                'au' => $end->toIso8601String(),
                'fuseau_horaire' => $timezone,
            ],
            'resume' => $summary,
            'periode_precedente' => [
                'du' => $previousStart->toIso8601String(),
                'au' => $previousEnd->toIso8601String(),
                'chiffre_affaires' => $previousSummary['chiffre_affaires'],
                'nombre_ventes' => $previousSummary['nombre_ventes'],
                'articles_vendus' => $previousSummary['articles_vendus'],
            ],
            'evolution' => [
                'chiffre_affaires' => $this->percentChange($previousSummary['chiffre_affaires'], $summary['chiffre_affaires']),
                'nombre_ventes' => $this->percentChange((string) $previousSummary['nombre_ventes'], (string) $summary['nombre_ventes']),
                'articles_vendus' => $this->percentChange($previousSummary['articles_vendus'], $summary['articles_vendus']),
                'panier_moyen' => $this->percentChange($previousSummary['panier_moyen'], $summary['panier_moyen']),
            ],
            'courbe' => $this->curve($store, $period, $start, $end),
            'meilleurs_produits' => $this->topProducts($store, $start, $end),
            'modes_paiement' => $this->paymentMethods($store, $start, $end),
        ];
    }

    /**
     * @return array{chiffre_affaires: string, nombre_ventes: int, panier_moyen: string, articles_vendus: string, remises: string, benefice_estime: string|null, produits_sans_prix_achat: int}
     */
    private function summary(Store $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = $this->sales($store, $start, $end)
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(montant_total), 0) as total, COALESCE(SUM(montant_remise), 0) as remises')
            ->first();

        $revenue = Money::round($this->decimal($totals->total));
        $discounts = Money::round($this->decimal($totals->remises));
        $count = (int) $totals->nombre;

        $lines = $this->lines($store, $start, $end)
            ->leftJoin('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->selectRaw('COALESCE(SUM(lignes_vente.quantite), 0) as quantite')
            ->selectRaw('COALESCE(SUM(CASE WHEN produits.prix_achat IS NOT NULL THEN lignes_vente.montant_total - lignes_vente.quantite * produits.prix_achat END), 0) as marge')
            ->selectRaw('COUNT(CASE WHEN produits.prix_achat IS NOT NULL THEN 1 END) as lignes_avec_cout')
            ->selectRaw('COUNT(DISTINCT CASE WHEN produits.prix_achat IS NULL THEN lignes_vente.produit_id END) as produits_sans_cout')
            ->first();

        // Estimated against the product's CURRENT purchase price (not
        // snapshotted on the sale line) and only over lines whose product
        // has one — hence "estimé". Sale-level discounts are subtracted.
        $estimatedProfit = (int) $lines->lignes_avec_cout > 0
            ? Money::round(bcsub($this->decimal($lines->marge), $discounts, 4))
            : null;

        return [
            'chiffre_affaires' => $revenue,
            'nombre_ventes' => $count,
            'panier_moyen' => $count > 0 ? Money::round(bcdiv($revenue, (string) $count, 4)) : '0.00',
            'articles_vendus' => $this->quantity($this->decimal($lines->quantite)),
            'remises' => $discounts,
            'benefice_estime' => $estimatedProfit,
            'produits_sans_prix_achat' => (int) $lines->produits_sans_cout,
        ];
    }

    /**
     * Bucketed in PHP rather than with DATE()/HOUR() in SQL: the buckets
     * must follow the store's timezone, and those SQL functions differ
     * between MySQL (production) and SQLite (tests).
     *
     * @return array{granularite: string, points: array<int, array{cle: string, libelle: string, chiffre_affaires: string, nombre_ventes: int}>}
     */
    private function curve(Store $store, ReportPeriod $period, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $format = $period->isHourly() ? 'H' : 'Y-m-d';
        $buckets = [];

        if ($period->isHourly()) {
            for ($hour = 0; $hour < 24; $hour++) {
                $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
                $buckets[$key] = ['cle' => $key, 'libelle' => "{$key}h", 'chiffre_affaires' => '0', 'nombre_ventes' => 0];
            }
        } else {
            for ($day = $start->startOfDay(); $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                $buckets[$day->format($format)] = ['cle' => $day->format($format), 'libelle' => $day->format('d/m'), 'chiffre_affaires' => '0', 'nombre_ventes' => 0];
            }
        }

        $rows = $this->sales($store, $start, $end)->select(['vendue_le', 'montant_total'])->get();

        foreach ($rows as $row) {
            $key = CarbonImmutable::parse($row->vendue_le, 'UTC')->setTimezone($start->getTimezone())->format($format);

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['chiffre_affaires'] = bcadd($buckets[$key]['chiffre_affaires'], $this->decimal($row->montant_total), 4);
            $buckets[$key]['nombre_ventes']++;
        }

        return [
            'granularite' => $period->isHourly() ? 'heure' : 'jour',
            'points' => array_values(array_map(
                fn (array $bucket) => [...$bucket, 'chiffre_affaires' => Money::round($bucket['chiffre_affaires'])],
                $buckets,
            )),
        ];
    }

    /**
     * @return array<int, array{produit_id: int, nom: string, quantite: string, chiffre_affaires: string}>
     */
    private function topProducts(Store $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->lines($store, $start, $end)
            ->selectRaw('lignes_vente.produit_id, MAX(lignes_vente.nom_produit) as nom, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
            ->groupBy('lignes_vente.produit_id')
            ->orderByDesc('total')
            ->limit(self::TOP_PRODUCTS_LIMIT)
            ->get()
            ->map(fn (object $row) => [
                'produit_id' => (int) $row->produit_id,
                'nom' => $row->nom,
                'quantite' => $this->quantity($this->decimal($row->quantite)),
                'chiffre_affaires' => Money::round($this->decimal($row->total)),
            ])
            ->all();
    }

    /**
     * @return array<int, array{mode: string, montant: string, nombre_ventes: int}>
     */
    private function paymentMethods(Store $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->sales($store, $start, $end)
            ->selectRaw('mode_paiement, COUNT(*) as nombre, SUM(montant_total) as total')
            ->groupBy('mode_paiement')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => [
                'mode' => $row->mode_paiement,
                'montant' => Money::round($this->decimal($row->total)),
                'nombre_ventes' => (int) $row->nombre,
            ])
            ->all();
    }

    private function sales(Store $store, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('ventes')
            ->where('boutique_id', $store->id)
            ->where('statut', SaleStatus::Completed->value)
            ->whereBetween('vendue_le', [$this->utc($start), $this->utc($end)]);
    }

    private function lines(Store $store, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DB::table('lignes_vente')
            ->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('lignes_vente.boutique_id', $store->id)
            ->where('ventes.boutique_id', $store->id)
            ->where('ventes.statut', SaleStatus::Completed->value)
            ->whereBetween('ventes.vendue_le', [$this->utc($start), $this->utc($end)]);
    }

    /** `vendue_le` is stored in the application timezone (UTC). */
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
     * Rounded to one decimal; null when there is nothing to compare with
     * (any growth from zero would be an infinite percentage).
     */
    private function percentChange(string $previous, string $current): ?float
    {
        if (bccomp($previous, '0', 4) === 0) {
            return null;
        }

        return round((float) bcmul(bcdiv(bcsub($current, $previous, 4), $previous, 6), '100', 4), 1);
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

<?php

namespace App\Modules\Reports\Services;

use App\Modules\Appointments\Support\SalonSettings;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Reports\Enums\ReportPeriod;
use App\Modules\Reports\Support\PressingStats;
use App\Modules\Reports\Support\QueriesStoreData;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rapport de gestion (feature `rapports_gestion`, boucherie) : ventes au
 * poids, par catégorie et par morceau, stock valorisé, achats et dettes,
 * créances, dépenses, pertes, marge et bénéfice net estimés. Lecture seule,
 * sur les données réelles des autres modules ; chaque section n'apparaît
 * que si la feature correspondante est active. Requêtes query-builder,
 * filtrées explicitement sur boutique_id (README §Règles).
 */
class ManagementReportService
{
    use QueriesStoreData;

    private const TOP_LIMIT = 10;

    public function __construct(private readonly FeatureGate $features) {}

    public function build(Store $store, ReportPeriod $period, ?CarbonImmutable $now = null, ?array $custom = null): array
    {
        $timezone = $this->timezoneOf($store);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        [$start, $end] = $period->range($now, $custom);
        $from = $this->utc($start);
        $to = $this->utc($end);
        $has = fn (string $feature) => $this->features->allows($store, $feature);

        $sales = $has('ventes') ? $this->sales($store, $from, $to, $has('marques_modeles')) : null;
        $stock = $has('stock') ? $this->stock($store) : null;
        $purchases = $has('fournisseurs') ? $this->purchases($store, $from, $to) : null;
        $receivables = $has('clients') ? $this->receivables($store) : null;
        $expenses = ($has('depenses') || $has('employes')) ? $this->expenses($store, $from, $to, $has('depenses'), $has('employes')) : null;
        $losses = ($has('stock') || $has('decoupe')) ? $this->losses($store, $from, $to, $has('decoupe')) : null;
        $warranties = $has('garanties') ? $this->warranties($store) : null;
        $repairs = $has('reparations') ? $this->repairs($store, $from, $to) : null;
        $restaurant = $has('commandes') && ($has('tables') || $has('cuisine')) ? $this->restaurant($store, $from, $to, $start, $has) : null;
        $fashion = $has('variantes') || $has('collections') ? $this->fashion($store, $from, $to, $has) : null;
        $returns = $has('retours') ? $this->returns($store, $from, $to) : null;
        $expiries = $has('conservation') ? $this->expiries($store) : null;
        $supplies = $has('fournisseurs') ? $this->supplies($store, $from, $to) : null;
        $salon = $has('rendez_vous') ? $this->salon($store, $from, $to, $start, $end, $has) : null;
        $pressing = $has('articles_depot') ? app(PressingStats::class)->build($store, $from, $to) : null;
        if ($stock !== null && $has('appareils')) {
            $stock['appareils_en_stock'] = DB::table('appareils')->where('boutique_id', $store->id)->where('statut', 'en_stock')->count();
            $stock['appareils_defectueux'] = DB::table('appareils')->where('boutique_id', $store->id)->where('statut', 'defectueux')->count();
        }

        // Bénéfice net estimé = marge commerciale − dépenses − pertes de stock.
        // Les pertes de découpe ne sont pas retirées : elles sont déjà dans le
        // coût de revient des morceaux, donc dans la marge.
        $margin = $sales['marge_commerciale'] ?? null;
        // Réparations : revenus encaissés moins le coût des pièces utilisées.
        $repairMargin = $repairs === null ? '0' : bcsub($repairs['revenus'], $repairs['cout_pieces'], 2);
        $net = $margin === null ? null : Money::round(bcadd(bcsub(bcsub($margin, $expenses['total'] ?? '0', 4), $losses['valeur_stock'] ?? '0', 4), $repairMargin, 4));

        return [
            'periode' => ['code' => $period->value, 'libelle' => $period->label(), 'du' => $start->toIso8601String(), 'au' => $end->toIso8601String(), 'fuseau_horaire' => $timezone],
            'ventes' => $sales,
            'stock' => $stock,
            'achats' => $purchases,
            'creances_clients' => $receivables,
            'depenses' => $expenses,
            'pertes' => $losses,
            'garanties' => $warranties,
            'reparations' => $repairs,
            'restaurant' => $restaurant,
            'mode' => $fashion,
            'retours' => $returns,
            'peremptions' => $expiries,
            'approvisionnements' => $supplies,
            'salon' => $salon,
            'pressing' => $pressing,
            'resultat' => [
                'chiffre_affaires' => $sales['chiffre_affaires'] ?? null,
                'marge_commerciale' => $margin,
                'depenses' => $expenses['total'] ?? null,
                'pertes' => $losses['valeur_stock'] ?? null,
                'marge_reparations' => $repairs === null ? null : $repairMargin,
                'benefice_net_estime' => $net,
            ],
        ];
    }

    /**
     * Restaurant : commandes de la période et en cours, temps moyen de
     * préparation (commande → plat prêt), plats / boissons vendus, salle et
     * réservations du jour.
     *
     * @param  callable(string): bool  $has
     * @return array<string, mixed>
     */
    private function restaurant(Store $store, string $from, string $to, CarbonImmutable $start, callable $has): array
    {
        $orders = DB::table('commandes')->where('boutique_id', $store->id);
        $open = (clone $orders)->whereIn('statut', ['en_attente', 'en_preparation', 'prete', 'servie'])
            ->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut');

        $prepared = DB::table('lignes_commande')
            ->join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->where('lignes_commande.boutique_id', $store->id)
            ->whereNotNull('lignes_commande.prete_le')
            ->whereBetween('lignes_commande.prete_le', [$from, $to])
            ->get(['commandes.created_at', 'lignes_commande.prete_le']);
        $minutes = $prepared->map(fn (object $row) => max(0, (CarbonImmutable::parse($row->prete_le)->getTimestamp() - CarbonImmutable::parse($row->created_at)->getTimestamp()) / 60));

        $byKind = $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->selectRaw('produits.type_article as type, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
            ->groupBy('produits.type_article')
            ->get()
            ->map(fn (object $row) => ['type_article' => $row->type, 'quantite' => $this->quantity($this->decimal($row->quantite)), 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->values()->all();
        $topDrinks = $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->where('produits.type_article', 'boisson')
            ->selectRaw('produits.id, MAX(produits.nom) as nom, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
            ->groupBy('produits.id')->orderByDesc('quantite')->limit(5)->get()
            ->map(fn (object $row) => ['produit_id' => (int) $row->id, 'nom' => $row->nom, 'quantite' => $this->quantity($this->decimal($row->quantite)), 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->all();

        $tables = $has('tables') ? DB::table('tables_salle')->where('boutique_id', $store->id)->where('actif', true)->pluck('id') : collect();
        $busy = $tables->isEmpty() ? 0 : (clone $orders)->whereIn('table_id', $tables)->whereIn('statut', ['en_attente', 'en_preparation', 'prete', 'servie'])->distinct()->count('table_id');
        $dayStart = CarbonImmutable::now($start->getTimezone())->startOfDay();

        return [
            'nombre_commandes' => (clone $orders)->whereBetween('created_at', [$from, $to])->where('statut', '!=', 'annulee')->count(),
            'commandes_annulees' => (clone $orders)->whereBetween('created_at', [$from, $to])->where('statut', 'annulee')->count(),
            'en_attente' => (int) ($open['en_attente'] ?? 0),
            'en_preparation' => (int) ($open['en_preparation'] ?? 0),
            'pretes' => (int) ($open['prete'] ?? 0),
            'servies_non_payees' => (int) ($open['servie'] ?? 0),
            'temps_moyen_preparation_minutes' => $minutes->isEmpty() ? null : round($minutes->avg(), 1),
            'par_type' => $byKind,
            'boissons_plus_vendues' => $topDrinks,
            'tables' => $has('tables') ? ['total' => $tables->count(), 'occupees' => $busy, 'libres' => $tables->count() - $busy] : null,
            'reservations_du_jour' => $has('reservations')
                ? DB::table('reservations')->where('boutique_id', $store->id)->whereIn('statut', ['confirmee', 'arrivee'])
                    ->whereBetween('prevue_le', [$this->utc($dayStart), $this->utc($dayStart->endOfDay())])->count()
                : null,
        ];
    }

    /**
     * Vêtements : ventes par taille, couleur et collection ; variantes en
     * rupture ; articles invendus (en stock, aucune vente depuis 60 jours).
     *
     * @param  callable(string): bool  $has
     * @return array<string, mixed>
     */
    private function fashion(Store $store, string $from, string $to, callable $has): array
    {
        $by = fn (string $column) => $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->whereNotNull("produits.{$column}")
            ->selectRaw("produits.{$column} as valeur, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total")
            ->groupBy("produits.{$column}")->orderByDesc('quantite')->limit(15)->get()
            ->map(fn (object $row) => ['valeur' => $row->valeur, 'quantite' => $this->quantity($this->decimal($row->quantite)), 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->all();

        $byCollection = $has('collections') ? $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->join('collections', 'collections.id', '=', 'produits.collection_id')
            ->selectRaw('collections.id, MAX(collections.nom) as nom, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
            ->groupBy('collections.id')->orderByDesc('total')->get()
            ->map(fn (object $row) => ['collection_id' => (int) $row->id, 'nom' => $row->nom, 'quantite' => $this->quantity($this->decimal($row->quantite)), 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->all() : [];

        $outOfStock = DB::table('stocks')->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->where('stocks.boutique_id', $store->id)->whereNull('produits.deleted_at')->where('produits.actif', true)
            ->whereNotNull('produits.produit_parent_id')->where('stocks.quantite', '<=', 0)
            ->orderBy('produits.nom')->limit(30)->get(['produits.id', 'produits.nom', 'produits.taille', 'produits.couleur'])
            ->map(fn (object $row) => ['produit_id' => (int) $row->id, 'nom' => $row->nom, 'taille' => $row->taille, 'couleur' => $row->couleur])->all();

        $since = CarbonImmutable::now()->subDays(60)->format('Y-m-d H:i:s');
        $sold = DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('lignes_vente.boutique_id', $store->id)->where('ventes.statut', 'terminee')->where('ventes.vendue_le', '>=', $since)
            ->whereNotNull('lignes_vente.produit_id')->distinct()->pluck('lignes_vente.produit_id');
        $unsold = DB::table('stocks')->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->where('stocks.boutique_id', $store->id)->whereNull('produits.deleted_at')->where('stocks.quantite', '>', 0)
            ->whereNotIn('produits.id', $sold)->where('stocks.created_at', '<=', $since)
            ->selectRaw('produits.id, produits.nom, stocks.quantite, stocks.quantite * COALESCE(produits.prix_achat, 0) as valeur')
            ->orderByDesc('valeur')->limit(20)->get()
            ->map(fn (object $row) => ['produit_id' => (int) $row->id, 'nom' => $row->nom, 'quantite' => $this->quantity($this->decimal($row->quantite)), 'valeur' => Money::round($this->decimal($row->valeur))])->all();

        return [
            'par_taille' => $by('taille'),
            'par_couleur' => $by('couleur'),
            'par_collection' => $byCollection,
            'variantes_en_rupture' => $outOfStock,
            'invendus_60_jours' => $unsold,
        ];
    }

    /**
     * Péremptions : lots périmés et à venir par seuil (paramètres de la
     * boutique, défaut 30/60/90/180 j), lots bloqués et rappelés, valeur au
     * prix d'achat du lot (sinon du produit).
     *
     * @return array<string, mixed>
     */
    private function expiries(Store $store): array
    {
        $thresholds = collect($store->parametres['seuils_peremption'] ?? [30, 60, 90, 180])->map(fn ($v) => (int) $v)->filter()->sort()->values();
        $today = CarbonImmutable::now()->toDateString();
        $base = fn () => DB::table('lots_stock')->join('produits', 'produits.id', '=', 'lots_stock.produit_id')
            ->where('lots_stock.boutique_id', $store->id)->where('lots_stock.quantite_restante', '>', 0)
            ->selectRaw('COUNT(*) as lots, COALESCE(SUM(lots_stock.quantite_restante), 0) as quantite, COALESCE(SUM(lots_stock.quantite_restante * COALESCE(lots_stock.prix_achat, produits.prix_achat, 0)), 0) as valeur');
        $row = fn (object $r) => ['lots' => (int) $r->lots, 'quantite' => $this->quantity($this->decimal($r->quantite)), 'valeur' => Money::round($this->decimal($r->valeur))];

        return [
            'perimes' => $row($base()->whereNotNull('lots_stock.date_limite')->whereDate('lots_stock.date_limite', '<', $today)->first()),
            'a_venir' => $thresholds->map(fn (int $days) => ['jours' => $days, ...$row($base()->whereNotNull('lots_stock.date_limite')->whereDate('lots_stock.date_limite', '>=', $today)
                ->whereDate('lots_stock.date_limite', '<=', CarbonImmutable::now()->addDays($days)->toDateString())->first())])->all(),
            'bloques' => $row($base()->where('lots_stock.statut', 'bloque')->first()),
            'rappeles' => $row($base()->where('lots_stock.statut', 'rappele')->first()),
        ];
    }

    /** Approvisionnements : achats reçus de la période, par fournisseur, produits les plus réapprovisionnés, commandes en attente. */
    private function supplies(Store $store, string $from, string $to): array
    {
        $received = DB::table('achats')->where('achats.boutique_id', $store->id)->where('achats.statut', '!=', 'commande')->whereBetween('achats.achete_le', [$from, $to]);

        return [
            'nombre' => (clone $received)->count(),
            'total' => Money::round($this->decimal((clone $received)->sum('montant_total'))),
            'par_fournisseur' => (clone $received)->leftJoin('fournisseurs', 'fournisseurs.id', '=', 'achats.fournisseur_id')
                ->selectRaw('achats.fournisseur_id, MAX(fournisseurs.nom) as nom, COUNT(*) as nombre, SUM(achats.montant_total) as total')
                ->groupBy('achats.fournisseur_id')->orderByDesc('total')->limit(5)->get()
                ->map(fn (object $r) => ['fournisseur_id' => $r->fournisseur_id !== null ? (int) $r->fournisseur_id : null, 'nom' => $r->nom ?? 'Sans fournisseur', 'nombre' => (int) $r->nombre, 'total' => Money::round($this->decimal($r->total))])->all(),
            'plus_reapprovisionnes' => DB::table('lignes_achat')->join('achats', 'achats.id', '=', 'lignes_achat.achat_id')
                ->where('achats.boutique_id', $store->id)->where('achats.statut', '!=', 'commande')->whereBetween('achats.achete_le', [$from, $to])
                ->selectRaw('lignes_achat.produit_id, MAX(lignes_achat.nom_produit) as nom, COUNT(*) as receptions, SUM(lignes_achat.quantite) as quantite')
                ->groupBy('lignes_achat.produit_id')->orderByDesc('receptions')->orderByDesc('quantite')->limit(5)->get()
                ->map(fn (object $r) => ['produit_id' => (int) $r->produit_id, 'nom' => $r->nom, 'receptions' => (int) $r->receptions, 'quantite' => $this->quantity($this->decimal($r->quantite))])->all(),
            'commandes_en_attente' => DB::table('achats')->where('boutique_id', $store->id)->where('statut', 'commande')->count(),
        ];
    }

    /** Retours et échanges de la période : nombre, articles, remboursé, articles les plus retournés. */
    private function returns(Store $store, string $from, string $to): array
    {
        $returns = DB::table('retours')->where('boutique_id', $store->id)->whereBetween('created_at', [$from, $to]);
        $lines = DB::table('lignes_retour')->join('retours', 'retours.id', '=', 'lignes_retour.retour_id')
            ->where('retours.boutique_id', $store->id)->whereBetween('retours.created_at', [$from, $to]);

        return [
            'nombre' => (clone $returns)->count(),
            'echanges' => (clone $returns)->where('decision', 'echange')->count(),
            'articles' => $this->quantity($this->decimal((clone $lines)->sum('lignes_retour.quantite'))),
            'rembourse' => Money::round($this->decimal(bcadd($this->decimal((clone $returns)->where('decision', 'remboursement')->sum('montant')), $this->decimal((clone $returns)->sum('montant_rendu')), 2))),
            'avoirs' => Money::round($this->decimal((clone $returns)->where('decision', 'avoir')->sum('montant'))),
            'defectueux' => (clone $returns)->where('etat_produits', 'defectueux')->count(),
            'plus_retournes' => (clone $lines)->selectRaw('lignes_retour.produit_id, MAX(lignes_retour.nom_produit) as nom, SUM(lignes_retour.quantite) as quantite')
                ->groupBy('lignes_retour.produit_id')->orderByDesc('quantite')->limit(5)->get()
                ->map(fn (object $row) => ['produit_id' => (int) $row->produit_id, 'nom' => $row->nom, 'quantite' => $this->quantity($this->decimal($row->quantite))])->all(),
        ];
    }

    private function sales(Store $store, string $from, string $to, bool $withBrands = false): array
    {
        $totals = DB::table('ventes')
            ->where('boutique_id', $store->id)->where('statut', SaleStatus::Completed->value)->whereBetween('vendue_le', [$from, $to])
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(montant_total), 0) as total, COALESCE(SUM(montant_remise), 0) as remises')
            ->first();
        $discounts = Money::round($this->decimal($totals->remises));

        $weightExpr = "CASE WHEN produits.unite = 'kg' THEN lignes_vente.quantite WHEN produits.unite = 'g' THEN lignes_vente.quantite / 1000 ELSE 0 END";
        $agg = $this->lines($store, $from, $to)
            ->leftJoin('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->selectRaw("COALESCE(SUM({$weightExpr}), 0) as poids")
            ->selectRaw('COALESCE(SUM(CASE WHEN produits.prix_achat IS NOT NULL THEN lignes_vente.quantite * produits.prix_achat END), 0) as cout')
            ->selectRaw('COALESCE(SUM(CASE WHEN produits.prix_achat IS NOT NULL THEN lignes_vente.montant_total - lignes_vente.quantite * produits.prix_achat END), 0) as marge')
            ->selectRaw('COUNT(CASE WHEN produits.prix_achat IS NOT NULL THEN 1 END) as lignes_avec_cout')
            ->selectRaw('COUNT(DISTINCT CASE WHEN lignes_vente.produit_id IS NOT NULL AND produits.prix_achat IS NULL THEN lignes_vente.produit_id END) as sans_cout')
            ->first();

        $byCategory = $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->leftJoin('categories', 'categories.id', '=', 'produits.categorie_id')
            ->selectRaw("categories.id as categorie_id, MAX(categories.nom) as nom, SUM(lignes_vente.montant_total) as total, SUM({$weightExpr}) as poids")
            ->selectRaw('SUM(CASE WHEN produits.prix_achat IS NOT NULL THEN lignes_vente.montant_total - lignes_vente.quantite * produits.prix_achat END) as marge')
            ->groupBy('categories.id')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => [
                'categorie_id' => $row->categorie_id !== null ? (int) $row->categorie_id : null,
                'nom' => $row->nom ?? 'Sans catégorie',
                'chiffre_affaires' => Money::round($this->decimal($row->total)),
                'poids_kg' => $this->quantity($this->decimal($row->poids)),
                'marge' => $row->marge === null ? null : Money::round($this->decimal($row->marge)),
            ])->all();

        $byProduct = $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->selectRaw('lignes_vente.produit_id, MAX(lignes_vente.nom_produit) as nom, MAX(produits.unite) as unite, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
            ->selectRaw('SUM(CASE WHEN produits.prix_achat IS NOT NULL THEN lignes_vente.montant_total - lignes_vente.quantite * produits.prix_achat END) as marge')
            ->groupBy('lignes_vente.produit_id')
            ->orderByDesc('total')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn (object $row) => [
                'produit_id' => (int) $row->produit_id,
                'nom' => $row->nom,
                'unite' => $row->unite,
                'quantite' => $this->quantity($this->decimal($row->quantite)),
                'chiffre_affaires' => Money::round($this->decimal($row->total)),
                'marge' => $row->marge === null ? null : Money::round($this->decimal($row->marge)),
            ])->all();

        $byBrand = $withBrands ? $this->lines($store, $from, $to)
            ->join('produits', 'produits.id', '=', 'lignes_vente.produit_id')
            ->whereNotNull('produits.marque')
            ->selectRaw('produits.marque as marque, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
            ->groupBy('produits.marque')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => ['marque' => $row->marque, 'quantite' => $this->quantity($this->decimal($row->quantite)), 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->all() : null;

        $bySeller = DB::table('ventes')
            ->leftJoin('utilisateurs', 'utilisateurs.id', '=', 'ventes.vendeur_id')
            ->where('ventes.boutique_id', $store->id)->where('ventes.statut', SaleStatus::Completed->value)->whereBetween('ventes.vendue_le', [$from, $to])
            ->selectRaw('ventes.vendeur_id, MAX(utilisateurs.nom) as nom, COUNT(*) as nombre, SUM(ventes.montant_total) as total')
            ->groupBy('ventes.vendeur_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row) => ['vendeur_id' => $row->vendeur_id !== null ? (int) $row->vendeur_id : null, 'nom' => $row->nom ?? '—', 'nombre_ventes' => (int) $row->nombre, 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->all();

        return [
            'par_marque' => $byBrand,
            'par_vendeur' => $bySeller,
            'chiffre_affaires' => Money::round($this->decimal($totals->total)),
            'nombre_ventes' => (int) $totals->nombre,
            'remises' => $discounts,
            'poids_vendu_kg' => $this->quantity($this->decimal($agg->poids)),
            'cout_marchandises' => Money::round($this->decimal($agg->cout)),
            // Même règle que le tableau de bord : prix d'achat actuel, lignes qui en ont un, remises de vente déduites.
            'marge_commerciale' => (int) $agg->lignes_avec_cout > 0 ? Money::round(bcsub($this->decimal($agg->marge), $discounts, 4)) : null,
            'produits_sans_prix_achat' => (int) $agg->sans_cout,
            'par_categorie' => $byCategory,
            'par_produit' => $byProduct,
            'facturation' => $this->billing($store, $from, $to, Money::round($this->decimal($totals->total))),
        ];
    }

    /**
     * Facturé ≠ encaissé, pour tous les métiers : chiffre d'affaires produits /
     * prestations / forfaits, part mise à crédit sur la période, encaissé
     * (facturé − crédit + remboursements de crédit reçus), ventes annulées.
     *
     * @return array<string, string>
     */
    private function billing(Store $store, string $from, string $to, string $billed): array
    {
        $sum = fn ($q) => Money::round($this->decimal($q->sum('lignes_vente.montant_total')));
        $entries = DB::table('mouvements_compte_client')->where('boutique_id', $store->id)->whereBetween('created_at', [$from, $to]);
        $onCredit = Money::round($this->decimal((clone $entries)->where('type', 'vente_credit')->sum('montant')));
        // Remboursement de crédit : montant enregistré en négatif sur le compte client ; on en prend la valeur absolue.
        $repaid = ltrim(Money::round($this->decimal((clone $entries)->where('type', 'paiement')->sum('montant'))), '-');

        return [
            'facture' => $billed,
            'produits' => $sum($this->lines($store, $from, $to)->whereNotNull('lignes_vente.produit_id')),
            'prestations' => $sum($this->lines($store, $from, $to)->whereNotNull('lignes_vente.service_id')),
            'forfaits' => $sum($this->lines($store, $from, $to)->whereNotNull('lignes_vente.forfait_id')),
            'mis_a_credit' => $onCredit,
            'remboursements_credit_recus' => $repaid,
            'encaisse' => Money::round(bcadd(bcsub($billed, $onCredit, 2), $repaid, 2)),
            'reste_du_clients' => Money::round($this->decimal(DB::table('mouvements_compte_client')->where('boutique_id', $store->id)->sum('montant'))),
            'ventes_annulees' => Money::round($this->decimal(DB::table('ventes')->where('boutique_id', $store->id)->where('statut', SaleStatus::Cancelled->value)->whereBetween('annulee_le', [$from, $to])->sum('montant_total'))),
        ];
    }

    private function stock(Store $store): array
    {
        $row = DB::table('stocks')
            ->join('produits', 'produits.id', '=', 'stocks.produit_id')
            ->where('stocks.boutique_id', $store->id)
            ->whereNull('produits.deleted_at')
            ->selectRaw('COALESCE(SUM(CASE WHEN stocks.quantite > 0 AND produits.prix_achat IS NOT NULL THEN stocks.quantite * produits.prix_achat END), 0) as valeur')
            ->selectRaw("COALESCE(SUM(CASE WHEN stocks.quantite > 0 AND produits.unite = 'kg' THEN stocks.quantite WHEN stocks.quantite > 0 AND produits.unite = 'g' THEN stocks.quantite / 1000 ELSE 0 END), 0) as poids")
            ->selectRaw('COUNT(CASE WHEN stocks.quantite > 0 AND produits.prix_achat IS NULL THEN 1 END) as sans_cout')
            ->selectRaw('COUNT(CASE WHEN stocks.quantite_minimum IS NOT NULL AND stocks.quantite <= stocks.quantite_minimum THEN 1 END) as faibles')
            ->selectRaw('COUNT(CASE WHEN stocks.quantite <= 0 THEN 1 END) as ruptures')
            ->first();

        return [
            'valeur' => Money::round($this->decimal($row->valeur)),
            'poids_kg' => $this->quantity($this->decimal($row->poids)),
            'produits_sans_prix_achat' => (int) $row->sans_cout,
            'stock_faible' => (int) $row->faibles,
            'ruptures' => (int) $row->ruptures,
        ];
    }

    private function purchases(Store $store, string $from, string $to): array
    {
        $period = DB::table('achats')->where('boutique_id', $store->id)->where('statut', '!=', 'commande')->whereBetween('achete_le', [$from, $to])
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(montant_total), 0) as total')->first();
        // Dettes : tout ce qui reste dû aux fournisseurs aujourd'hui, toutes périodes confondues.
        $debts = DB::table('achats')->where('boutique_id', $store->id)->where('statut', '!=', 'commande')->whereColumn('montant_paye', '<', 'montant_total')
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(montant_total - montant_paye), 0) as reste')->first();

        return [
            'nombre' => (int) $period->nombre,
            'total' => Money::round($this->decimal($period->total)),
            'dettes_fournisseurs' => Money::round($this->decimal($debts->reste)),
            'achats_non_soldes' => (int) $debts->nombre,
        ];
    }

    /** Ce que les clients doivent aujourd'hui (soldes positifs des comptes clients). */
    private function receivables(Store $store): array
    {
        $balances = DB::table('mouvements_compte_client')
            ->where('boutique_id', $store->id)
            ->selectRaw('client_id, SUM(montant) as solde')
            ->groupBy('client_id')
            ->get()
            ->filter(fn (object $row) => bccomp($this->decimal($row->solde), '0', 2) > 0);

        return [
            'total' => Money::round($balances->reduce(fn (string $carry, object $row) => bcadd($carry, $this->decimal($row->solde), 4), '0')),
            'clients_debiteurs' => $balances->count(),
        ];
    }

    private function expenses(Store $store, string $from, string $to, bool $withExpenses, bool $withEmployees): array
    {
        $byCategory = $withExpenses
            ? DB::table('depenses')->where('boutique_id', $store->id)->where('statut', 'validee')->whereBetween('depense_le', [$from, $to])
                ->selectRaw('categorie, SUM(montant) as total')->groupBy('categorie')->orderByDesc('total')->get()
                ->map(fn (object $row) => ['categorie' => $row->categorie, 'total' => Money::round($this->decimal($row->total))])->all()
            : [];
        $expensesTotal = array_reduce($byCategory, fn (string $carry, array $row) => bcadd($carry, $row['total'], 2), '0.00');
        $salaries = $withEmployees
            ? Money::round($this->decimal(DB::table('paiements_employe')->where('boutique_id', $store->id)->whereBetween('paye_le', [$from, $to])->sum('montant')))
            : null;

        return [
            'depenses' => $withExpenses ? $expensesTotal : null,
            'salaires_employes' => $salaries,
            'total' => bcadd($expensesTotal, $salaries ?? '0', 2),
            'par_categorie' => $byCategory,
        ];
    }

    private function losses(Store $store, string $from, string $to, bool $withCutting): array
    {
        $stockLosses = DB::table('mouvements_stock')
            ->where('boutique_id', $store->id)->where('type', 'perte')->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(CASE WHEN cout_unitaire IS NOT NULL THEN -quantite * cout_unitaire END), 0) as valeur')
            ->first();

        $cutting = null;
        if ($withCutting) {
            $cuts = DB::table('transformations')
                ->where('boutique_id', $store->id)->where('type', 'decoupe')->where('statut', 'terminee')->where('perte', '>', 0)
                ->whereBetween('effectuee_le', [$from, $to])
                ->get(['perte', 'poids_entree', 'cout_matieres']);
            $cutting = [
                'poids_kg' => $this->quantity($cuts->reduce(fn (string $c, object $t) => bcadd($c, $this->decimal($t->perte), 3), '0')),
                // Informatif : déjà compris dans le coût de revient des morceaux.
                'valeur' => Money::round($cuts->reduce(fn (string $c, object $t) => bccomp($this->decimal($t->poids_entree), '0', 3) > 0
                    ? bcadd($c, bcdiv(bcmul($this->decimal($t->cout_matieres), $this->decimal($t->perte), 6), $this->decimal($t->poids_entree), 6), 6)
                    : $c, '0')),
            ];
        }

        return [
            'nombre_stock' => (int) $stockLosses->nombre,
            'valeur_stock' => Money::round($this->decimal($stockLosses->valeur)),
            'decoupe' => $cutting,
        ];
    }

    /** Garanties à date : actives, expirées, réclamations en cours. */
    private function warranties(Store $store): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $valid = fn () => DB::table('garanties')->where('boutique_id', $store->id)->where('statut', 'valide');

        return [
            'actives' => $valid()->whereDate('fin_le', '>=', $today)->count(),
            'expirees' => $valid()->whereDate('fin_le', '<', $today)->count(),
            'reclamations_en_cours' => DB::table('reclamations_garantie')->where('boutique_id', $store->id)->whereIn('statut', ['ouverte', 'en_cours'])->count(),
        ];
    }

    /** Réparations : en cours à date, livrées et revenus de la période, coût des pièces. */
    private function repairs(Store $store, string $from, string $to): array
    {
        $parts = DB::table('pieces_reparation')
            ->join('produits', 'produits.id', '=', 'pieces_reparation.produit_id')
            ->where('pieces_reparation.boutique_id', $store->id)->whereBetween('pieces_reparation.created_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(pieces_reparation.quantite * COALESCE(produits.prix_achat, 0)), 0) as cout')
            ->first();

        $repairs = DB::table('reparations')->where('boutique_id', $store->id);
        $open = ['recu', 'diagnostic', 'attente_validation', 'attente_pieces', 'en_reparation', 'repare'];
        $delivered = (clone $repairs)->where('statut', 'livre')->whereBetween('livre_le', [$from, $to])->get(['id', 'depose_le', 'livre_le', 'technicien_id']);
        $days = $delivered->map(fn (object $r) => max(0, (CarbonImmutable::parse($r->livre_le)->getTimestamp() - CarbonImmutable::parse($r->depose_le)->getTimestamp()) / 86400));
        $lines = DB::table('prestations_reparation')->where('boutique_id', $store->id)->whereBetween('created_at', [$from, $to]);
        $quotes = DB::table('devis')->where('boutique_id', $store->id)->whereBetween('created_at', [$from, $to]);

        $byTechnician = DB::table('reparations')
            ->join('employes', 'employes.id', '=', 'reparations.technicien_id')
            ->where('reparations.boutique_id', $store->id)
            ->where(fn ($q) => $q->whereIn('reparations.statut', $open)->orWhere(fn ($q) => $q->where('reparations.statut', 'livre')->whereBetween('reparations.livre_le', [$from, $to])))
            ->selectRaw("employes.id, MAX(employes.nom) as nom, SUM(CASE WHEN reparations.statut = 'livre' THEN 1 ELSE 0 END) as livres, SUM(CASE WHEN reparations.statut != 'livre' THEN 1 ELSE 0 END) as en_cours")
            ->groupBy('employes.id')->orderByDesc('livres')->get()
            ->map(fn (object $row) => ['employe_id' => (int) $row->id, 'nom' => $row->nom, 'livres' => (int) $row->livres, 'en_cours' => (int) $row->en_cours])->all();

        return [
            'crees' => (clone $repairs)->whereBetween('depose_le', [$from, $to])->count(),
            'en_cours' => (clone $repairs)->whereIn('statut', $open)->count(),
            'en_retard' => (clone $repairs)->whereIn('statut', $open)->whereNotNull('prevu_le')->whereDate('prevu_le', '<', now()->toDateString())->count(),
            'prets' => (clone $repairs)->where('statut', 'repare')->count(),
            'livrees' => $delivered->count(),
            'annules' => (clone $repairs)->where('statut', 'annule')->whereBetween('updated_at', [$from, $to])->count(),
            'duree_moyenne_jours' => $days->isEmpty() ? null : round($days->avg(), 1),
            'prestations_realisees' => (clone $lines)->where('type', 'prestation')->count(),
            'montant_prestations' => Money::round($this->decimal((clone $lines)->where('type', 'prestation')->sum('montant'))),
            'main_oeuvre' => Money::round($this->decimal((clone $lines)->where('type', 'main_oeuvre')->sum('montant'))),
            'revenus' => Money::round($this->decimal(DB::table('paiements_reparation')->where('boutique_id', $store->id)->whereBetween('created_at', [$from, $to])->sum('montant'))),
            'cout_pieces' => Money::round($this->decimal($parts->cout)),
            'par_technicien' => $byTechnician,
            'devis' => ['crees' => (clone $quotes)->count(), 'acceptes' => (clone $quotes)->whereIn('statut', ['accepte', 'transforme'])->count(), 'refuses' => (clone $quotes)->where('statut', 'refuse')->count()],
        ];
    }

    /**
     * Salon : prestations réalisées et les plus demandées, chiffre d'affaires
     * prestations / produits, par employé, annulations et absences, taux
     * d'occupation (si horaires configurés), coût des consommables,
     * commissions dues, clients nouveaux / réguliers / inactifs.
     *
     * @param  callable(string): bool  $has
     * @return array<string, mixed>
     */
    private function salon(Store $store, string $from, string $to, CarbonImmutable $start, CarbonImmutable $end, callable $has): array
    {
        $appointments = DB::table('rendez_vous')->where('boutique_id', $store->id)->whereBetween('debut_le', [$from, $to]);
        $byStatus = (clone $appointments)->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut');
        $total = (int) $byStatus->sum();
        $done = (int) ($byStatus['termine'] ?? 0);
        $rate = fn (int $n) => $total === 0 ? null : round($n * 100 / $total, 1);

        $items = DB::table('prestations_rendez_vous')
            ->join('rendez_vous', 'rendez_vous.id', '=', 'prestations_rendez_vous.rendez_vous_id')
            ->join('services', 'services.id', '=', 'prestations_rendez_vous.service_id')
            ->where('prestations_rendez_vous.boutique_id', $store->id)
            ->where('rendez_vous.statut', 'termine')
            ->whereBetween('rendez_vous.debut_le', [$from, $to]);
        $topServices = (clone $items)
            ->selectRaw('services.id, MAX(services.nom) as nom, COUNT(*) as nombre')
            ->groupBy('services.id')->orderByDesc('nombre')->limit(self::TOP_LIMIT)->get()
            ->map(fn (object $row) => ['service_id' => (int) $row->id, 'nom' => $row->nom, 'nombre' => (int) $row->nombre])->all();

        $serviceRevenue = Money::round($this->decimal($this->lines($store, $from, $to)->whereNotNull('lignes_vente.service_id')->sum('lignes_vente.montant_total')));
        $productRevenue = Money::round($this->decimal($this->lines($store, $from, $to)->whereNotNull('lignes_vente.produit_id')->sum('lignes_vente.montant_total')));
        $packageRevenue = Money::round($this->decimal($this->lines($store, $from, $to)->whereNotNull('lignes_vente.forfait_id')->sum('lignes_vente.montant_total')));

        $byEmployee = $this->lines($store, $from, $to)
            ->join('employes', 'employes.id', '=', 'lignes_vente.employe_id')
            ->whereNotNull('lignes_vente.service_id')
            ->selectRaw('employes.id, MAX(employes.nom) as nom, COUNT(*) as prestations, SUM(lignes_vente.montant_total) as total')
            ->groupBy('employes.id')->orderByDesc('total')->get()
            ->map(fn (object $row) => ['employe_id' => (int) $row->id, 'nom' => $row->nom, 'prestations' => (int) $row->prestations, 'chiffre_affaires' => Money::round($this->decimal($row->total))])
            ->all();

        // Consommables : ce qui est réellement sorti du stock pour les prestations (trace de l'encaissement).
        $consumption = DB::table('consommations_prestations')
            ->join('ventes', 'ventes.id', '=', 'consommations_prestations.vente_id')
            ->where('consommations_prestations.boutique_id', $store->id)
            ->where('ventes.statut', SaleStatus::Completed->value)
            ->whereBetween('ventes.vendue_le', [$from, $to]);
        $consumables = $has('consommables') ? Money::round($this->decimal((clone $consumption)->selectRaw('SUM(consommations_prestations.quantite * COALESCE(consommations_prestations.cout_unitaire, 0)) as cout')->value('cout'))) : null;
        $mostConsumed = $has('consommables')
            ? (clone $consumption)->join('produits', 'produits.id', '=', 'consommations_prestations.produit_id')
                ->selectRaw('produits.id, MAX(produits.nom) as nom, MAX(produits.unite) as unite, SUM(consommations_prestations.quantite) as quantite, SUM(consommations_prestations.quantite * COALESCE(consommations_prestations.cout_unitaire, 0)) as cout')
                ->groupBy('produits.id')->orderByDesc('quantite')->limit(self::TOP_LIMIT)->get()
                ->map(fn (object $r) => ['produit_id' => (int) $r->id, 'nom' => $r->nom, 'unite' => $r->unite, 'quantite' => $this->quantity($this->decimal($r->quantite)), 'cout' => Money::round($this->decimal($r->cout))])->all()
            : [];

        // Occupation : minutes réservées ÷ minutes d'ouverture × employés actifs (horaires du salon requis).
        $occupancy = null;
        $hours = SalonSettings::openingHours($store);
        $staff = DB::table('employes')->where('boutique_id', $store->id)->where('actif', true)->whereNull('deleted_at')->count();
        if ($hours !== null && $staff > 0) {
            $open = 0;
            for ($day = $start->startOfDay(); $day->lte($end); $day = $day->addDay()) {
                $slot = $hours[$day->dayOfWeekIso];
                if ($slot !== null) {
                    [$oh, $om] = array_map('intval', explode(':', $slot['ouvre']));
                    [$ch, $cm] = array_map('intval', explode(':', $slot['ferme']));
                    $open += ($ch * 60 + $cm) - ($oh * 60 + $om);
                }
            }
            $booked = (clone $appointments)->whereIn('statut', ['en_attente', 'prevu', 'confirme', 'en_cours', 'termine'])->get(['debut_le', 'fin_le'])
                ->sum(fn (object $a) => max(0, (CarbonImmutable::parse($a->fin_le)->getTimestamp() - CarbonImmutable::parse($a->debut_le)->getTimestamp()) / 60));
            $occupancy = $open === 0 ? null : round(min(100, $booked * 100 / ($open * $staff)), 1);
        }

        // Clients : visites terminées (rendez-vous).
        $visits = DB::table('rendez_vous')->where('boutique_id', $store->id)->where('statut', 'termine')->whereNotNull('client_id');
        $firstVisits = (clone $visits)->selectRaw('client_id, MIN(debut_le) as premiere')->groupBy('client_id')->get();
        $newCustomers = $firstVisits->filter(fn (object $r) => $r->premiere >= $from && $r->premiere <= $to)->count();
        $regulars = (clone $visits)->where('debut_le', '>=', $this->utc(CarbonImmutable::now()->subDays(90)))
            ->selectRaw('client_id, COUNT(*) as visites')->groupBy('client_id')->havingRaw('COUNT(*) >= 3')->get();
        $lastVisits = (clone $visits)->selectRaw('client_id, MAX(debut_le) as derniere')->groupBy('client_id')->get();
        $upcoming = DB::table('rendez_vous')->where('boutique_id', $store->id)->whereIn('statut', ['en_attente', 'prevu', 'confirme'])->where('debut_le', '>', now())->whereNotNull('client_id')->pluck('client_id')->unique();
        $inactive = $lastVisits->filter(fn (object $r) => $r->derniere < $this->utc(CarbonImmutable::now()->subDays(60)) && ! $upcoming->contains($r->client_id));
        $names = DB::table('clients')->whereIn('id', $regulars->pluck('client_id')->merge($inactive->pluck('client_id')))->pluck('nom', 'id');

        return [
            'rendez_vous' => $total,
            'termines' => $done,
            'annules' => (int) ($byStatus['annule'] ?? 0),
            'absents' => (int) ($byStatus['absent'] ?? 0),
            'taux_annulation' => $rate((int) ($byStatus['annule'] ?? 0)),
            'taux_absence' => $rate((int) ($byStatus['absent'] ?? 0)),
            'prestations_realisees' => (clone $items)->count(),
            'prestations_plus_demandees' => $topServices,
            'chiffre_affaires_prestations' => $serviceRevenue,
            'chiffre_affaires_produits' => $productRevenue,
            'chiffre_affaires_forfaits' => $packageRevenue,
            'par_employe' => $byEmployee,
            'taux_occupation' => $occupancy,
            'cout_consommables' => $consumables,
            'produits_plus_consommes' => $mostConsumed,
            'commissions_dues' => $has('commissions') ? Money::round($this->decimal(DB::table('commissions')->where('boutique_id', $store->id)->where('statut', 'due')->sum('montant'))) : null,
            'clients' => [
                'nouveaux' => $newCustomers,
                'reguliers' => $regulars->count(),
                'inactifs' => $inactive->count(),
                'liste_reguliers' => $regulars->sortByDesc('visites')->take(self::TOP_LIMIT)->map(fn (object $r) => ['client_id' => (int) $r->client_id, 'nom' => $names[$r->client_id] ?? '—', 'visites_90_jours' => (int) $r->visites])->values()->all(),
                'liste_inactifs' => $inactive->sortBy('derniere')->take(self::TOP_LIMIT)->map(fn (object $r) => ['client_id' => (int) $r->client_id, 'nom' => $names[$r->client_id] ?? '—', 'derniere_visite' => CarbonImmutable::parse($r->derniere)->toIso8601String()])->values()->all(),
            ],
        ];
    }

    private function lines(Store $store, string $from, string $to): Builder
    {
        return DB::table('lignes_vente')
            ->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('lignes_vente.boutique_id', $store->id)
            ->where('ventes.boutique_id', $store->id)
            ->where('ventes.statut', SaleStatus::Completed->value)
            ->whereBetween('ventes.vendue_le', [$from, $to]);
    }
}

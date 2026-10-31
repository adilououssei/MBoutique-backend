<?php

namespace App\Modules\Reports\Support;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DropOffTracker;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Pressing : commandes reçues, à traiter, en traitement, prêtes, remises,
 * en retard ; facturé (commandes reçues) ≠ encaissé (paiements réellement
 * reçus) ; soldes à recouvrer ; prestations les plus demandées ;
 * consommables ; pièces traitées par employé ; clients réguliers.
 */
class PressingStats
{
    private const OPEN = ['en_attente', 'en_preparation', 'controle', 'prete'];

    public function __construct(private readonly DropOffTracker $tracker) {}

    /** @return array<string, mixed> */
    public function build(Store $store, string $from, string $to): array
    {
        $orders = DB::table('commandes')->where('boutique_id', $store->id);
        $received = (clone $orders)->whereBetween('created_at', [$from, $to])->where('statut', '!=', 'annulee');
        $byStatus = (clone $orders)->whereIn('statut', self::OPEN)->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut');
        $lines = DB::table('lignes_commande')->join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->where('commandes.boutique_id', $store->id)->where('commandes.statut', '!=', 'annulee')
            ->whereBetween('commandes.created_at', [$from, $to]);

        // Soldes : commandes non closes (pas encore réglées) — total − acomptes, et restes mis sur le compte client.
        $balances = '0.00';
        foreach (Order::query()->whereIn('statut', [...self::OPEN, 'servie'])->with('items')->get() as $order) {
            $balances = bcadd($balances, max('0', $this->tracker->paymentSummary($order)['solde']), 2);
        }

        $consumption = DB::table('consommations_prestations')->join('commandes', 'commandes.id', '=', 'consommations_prestations.commande_id')
            ->where('consommations_prestations.boutique_id', $store->id)->whereBetween('consommations_prestations.created_at', [$from, $to]);

        return [
            'commandes_recues' => (clone $received)->count(),
            'a_traiter' => (int) ($byStatus['en_attente'] ?? 0),
            'en_traitement' => (int) ($byStatus['en_preparation'] ?? 0) + (int) ($byStatus['controle'] ?? 0),
            'en_controle' => (int) ($byStatus['controle'] ?? 0),
            'pretes' => (int) ($byStatus['prete'] ?? 0),
            'remises' => (clone $orders)->whereBetween('remise_le', [$from, $to])->count(),
            'remises_partielles' => DB::table('remises_depot')->where('boutique_id', $store->id)->whereBetween('remis_le', [$from, $to])->count(),
            'en_retard' => (clone $orders)->whereIn('statut', self::OPEN)->whereNotNull('date_promise')->where('date_promise', '<', now())->count(),
            'pieces_recues' => DB::table('articles_depot')->join('commandes', 'commandes.id', '=', 'articles_depot.commande_id')
                ->where('articles_depot.boutique_id', $store->id)->whereBetween('commandes.created_at', [$from, $to])->where('articles_depot.statut', '!=', 'annule')->count(),
            'facture' => Money::round((string) ((clone $lines)->selectRaw('SUM(lignes_commande.quantite * lignes_commande.prix_unitaire) as t')->value('t') ?? '0')),
            'encaisse' => Money::round((string) (DB::table('historique_commandes')->where('boutique_id', $store->id)->where('evenement', 'paiement')->whereBetween('created_at', [$from, $to])->sum('montant') ?? '0')),
            'soldes_a_recouvrer' => $balances,
            'prestations_plus_demandees' => (clone $lines)->whereNotNull('lignes_commande.service_id')
                ->selectRaw('lignes_commande.service_id as id, MAX(lignes_commande.nom) as nom, SUM(lignes_commande.quantite) as quantite, SUM(lignes_commande.quantite * lignes_commande.prix_unitaire) as total')
                ->groupBy('lignes_commande.service_id')->orderByDesc('total')->limit(10)->get()
                ->map(fn (object $r) => ['service_id' => (int) $r->id, 'nom' => $r->nom, 'quantite' => bcadd((string) $r->quantite, '0', 3), 'chiffre_affaires' => Money::round((string) $r->total)])->all(),
            'cout_consommables' => Money::round((string) ((clone $consumption)->selectRaw('SUM(consommations_prestations.quantite * COALESCE(consommations_prestations.cout_unitaire, 0)) as c')->value('c') ?? '0')),
            'produits_plus_consommes' => (clone $consumption)->join('produits', 'produits.id', '=', 'consommations_prestations.produit_id')
                ->selectRaw('produits.id, MAX(produits.nom) as nom, MAX(produits.unite) as unite, SUM(consommations_prestations.quantite) as quantite')
                ->groupBy('produits.id')->orderByDesc('quantite')->limit(10)->get()
                ->map(fn (object $r) => ['produit_id' => (int) $r->id, 'nom' => $r->nom, 'unite' => $r->unite, 'quantite' => bcadd((string) $r->quantite, '0', 3)])->all(),
            'par_employe' => DB::table('articles_depot')->join('employes', 'employes.id', '=', 'articles_depot.employe_id')
                ->where('articles_depot.boutique_id', $store->id)->whereBetween('articles_depot.updated_at', [$from, $to])
                ->selectRaw("employes.id, MAX(employes.nom) as nom, SUM(CASE WHEN articles_depot.statut IN ('pret', 'remis') THEN 1 ELSE 0 END) as traitees, SUM(CASE WHEN articles_depot.statut IN ('recu', 'en_traitement', 'controle') THEN 1 ELSE 0 END) as en_cours")
                ->groupBy('employes.id')->orderByDesc('traitees')->get()
                ->map(fn (object $r) => ['employe_id' => (int) $r->id, 'nom' => $r->nom, 'pieces_traitees' => (int) $r->traitees, 'pieces_en_cours' => (int) $r->en_cours])->all(),
            'clients_reguliers' => (clone $orders)->whereNotNull('client_id')->where('statut', '!=', 'annulee')->where('created_at', '>=', now()->subDays(90))
                ->selectRaw('client_id, COUNT(*) as n')->groupBy('client_id')->havingRaw('COUNT(*) >= 3')->orderByDesc('n')->limit(10)->get()
                ->map(fn (object $r) => ['client_id' => (int) $r->client_id, 'nom' => DB::table('clients')->where('id', $r->client_id)->value('nom'), 'commandes_90_jours' => (int) $r->n])->all(),
        ];
    }
}

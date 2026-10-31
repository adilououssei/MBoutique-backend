<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Support\Facades\DB;

/**
 * GET clients/{customer}/statistiques — fidélité vue par les chiffres :
 * visites (rendez-vous terminés, ou ventes à défaut), total dépensé,
 * prestations reçues et produits achetés, dates de première et dernière
 * visite. Calculé sur les ventes non annulées de la boutique.
 */
class CustomerStatsController extends ApiController
{
    public function show(Store $store, Customer $customer)
    {
        $this->authorize('view', [$customer, $store]);

        $sales = DB::table('ventes')->where('boutique_id', $store->id)->where('client_id', $customer->id)->where('statut', SaleStatus::Completed->value);
        $lines = DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', $store->id)->where('ventes.client_id', $customer->id)->where('ventes.statut', SaleStatus::Completed->value);
        $visits = DB::getSchemaBuilder()->hasTable('rendez_vous')
            ? DB::table('rendez_vous')->where('boutique_id', $store->id)->where('client_id', $customer->id)->where('statut', 'termine')
            : null;
        $visitCount = $visits?->count() ?? 0;

        return $this->success([
            'visites' => $visitCount > 0 ? $visitCount : (clone $sales)->count(),
            'achats' => (clone $sales)->count(),
            'total_depense' => Money::round((string) ((clone $sales)->sum('montant_total') ?? '0')),
            'premiere_visite' => (clone $sales)->min('vendue_le'),
            'derniere_visite' => max((string) (clone $sales)->max('vendue_le'), (string) ($visits?->max('debut_le') ?? '')) ?: null,
            'prestations' => (clone $lines)->whereNotNull('lignes_vente.service_id')
                ->selectRaw('lignes_vente.service_id as id, MAX(lignes_vente.nom_produit) as nom, SUM(lignes_vente.quantite) as nombre, SUM(lignes_vente.montant_total) as total, MAX(ventes.vendue_le) as derniere')
                ->groupBy('lignes_vente.service_id')->orderByDesc('nombre')->limit(10)->get()
                ->map(fn (object $r) => ['service_id' => (int) $r->id, 'nom' => $r->nom, 'nombre' => (int) $r->nombre, 'total' => Money::round((string) $r->total), 'derniere' => $r->derniere])->all(),
            'produits' => (clone $lines)->whereNotNull('lignes_vente.produit_id')
                ->selectRaw('lignes_vente.produit_id as id, MAX(lignes_vente.nom_produit) as nom, SUM(lignes_vente.quantite) as quantite, SUM(lignes_vente.montant_total) as total')
                ->groupBy('lignes_vente.produit_id')->orderByDesc('quantite')->limit(10)->get()
                ->map(fn (object $r) => ['produit_id' => (int) $r->id, 'nom' => $r->nom, 'quantite' => bcadd((string) $r->quantite, '0', 3), 'total' => Money::round((string) $r->total)])->all(),
        ]);
    }
}

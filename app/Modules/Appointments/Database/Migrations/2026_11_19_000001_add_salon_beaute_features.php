<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Salon de beauté : nouvelles features `consommables` (produits utilisés par
 * prestation, déduits du stock à l'encaissement), `forfaits` (séances
 * prépayées), `fidelite` (points) et `commissions` (option que le gérant
 * active). Le domaine inclut aussi produits, stock, fournisseurs, lots,
 * dépenses, équipements, rapport de gestion et promotions.
 */
return new class extends Migration
{
    private const NEW_FEATURES = [
        'consommables' => ['Consommables des prestations', ['services', 'stock', 'produits']],
        'forfaits' => ['Forfaits et abonnements', ['rendez_vous', 'clients', 'ventes']],
        'fidelite' => ['Fidélité', ['clients', 'ventes']],
        'commissions' => ['Commissions', ['employes', 'ventes']],
    ];

    private const DEFAULTS = ['produits', 'categories', 'stock', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion', 'promotions', 'consommables', 'forfaits', 'fidelite'];

    private const OPTIONAL = ['commissions'];

    public function up(): void
    {
        $now = now();
        foreach (self::NEW_FEATURES as $slug => [$name]) {
            [$category, $description] = FeatureCatalog::FEATURES[$slug];
            DB::table('fonctionnalites')->insertOrIgnore(['slug' => $slug, 'nom' => $name, 'categorie' => $category, 'description' => $description, 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        foreach (self::NEW_FEATURES as $slug => [, $dependencies]) {
            foreach ($dependencies as $dependency) {
                if (isset($ids[$dependency])) {
                    DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids[$slug], 'depend_de_fonctionnalite_id' => $ids[$dependency], 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        $domain = DB::table('domaines_activite')->where('slug', 'salon_beaute')->value('id');
        if ($domain === null) {
            return;
        }
        foreach ([...self::DEFAULTS, ...self::OPTIONAL] as $slug) {
            if (isset($ids[$slug]) && ! DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug]])->exists()) {
                $optional = in_array($slug, self::OPTIONAL, true);
                DB::table('fonctionnalites_domaine')->insert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug], 'active_par_defaut' => ! $optional, 'optionnelle' => $optional, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('fonctionnalites')->whereIn('slug', array_keys(self::NEW_FEATURES))->pluck('id');
        DB::table('fonctionnalites_domaine')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('fonctionnalites_boutique')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('dependances_fonctionnalites')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('fonctionnalites')->whereIn('id', $ids)->delete();
    }
};

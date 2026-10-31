<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domaine « Autre » configurable : ventes, caisse et rapports restent
 * toujours actifs ; produits, services, clients, catégories et dépenses
 * sont actifs par défaut mais désactivables ; stock, fournisseurs, suivi
 * des opérations (commandes), rapport de gestion et matériel sont
 * activables. Le propriétaire choisit dans « Paramètres du module », dans
 * la limite de son abonnement (FeatureGate).
 */
return new class extends Migration
{
    /** slug => actif par défaut */
    private const OPTIONS = [
        'produits' => true,
        'services' => true,
        'clients' => true,
        'categories' => true,
        'depenses' => true,
        'stock' => false,
        'fournisseurs' => false,
        'commandes' => false,
        'rapports_gestion' => false,
        'equipements' => false,
    ];

    public function up(): void
    {
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        $now = now();
        // Le stock suit des produits : sans catalogue de produits, il ne s'applique pas.
        if (isset($ids['stock'], $ids['produits'])) {
            DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids['stock'], 'depend_de_fonctionnalite_id' => $ids['produits'], 'created_at' => $now, 'updated_at' => $now]);
        }
        $domain = DB::table('domaines_activite')->where('slug', 'autre')->value('id');
        if ($domain === null) {
            return;
        }
        foreach (self::OPTIONS as $slug => $default) {
            if (! isset($ids[$slug])) {
                continue;
            }
            $where = ['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug]];
            if (DB::table('fonctionnalites_domaine')->where($where)->exists()) {
                DB::table('fonctionnalites_domaine')->where($where)->update(['optionnelle' => true, 'updated_at' => $now]);
            } else {
                DB::table('fonctionnalites_domaine')->insert([...$where, 'active_par_defaut' => $default, 'optionnelle' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $domain = DB::table('domaines_activite')->where('slug', 'autre')->value('id');
        if ($domain === null) {
            return;
        }
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        foreach (self::OPTIONS as $slug => $default) {
            if (! isset($ids[$slug])) {
                continue;
            }
            $where = ['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug]];
            $default
                ? DB::table('fonctionnalites_domaine')->where($where)->update(['optionnelle' => false])
                : DB::table('fonctionnalites_domaine')->where($where)->delete();
        }
    }
};

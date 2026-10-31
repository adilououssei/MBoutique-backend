<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pharmacie : nouvelles features `lots_stricts` (vente refusée sur lot
 * périmé, bloqué ou rappelé ; lot enregistré sur la vente), `conditionnement`
 * (boîte / unité), `commandes_fournisseurs` (commande → réception) et
 * `ordonnances`. Le domaine Pharmacie inclut aussi lots et péremptions
 * (`conservation`), retours, dépenses, rapport de gestion et promotions.
 */
return new class extends Migration
{
    private const NEW_FEATURES = [
        'lots_stricts' => ['Lots contrôlés à la vente', ['conservation', 'ventes']],
        'conditionnement' => ['Vente à la boîte ou à l’unité', ['produits', 'stock']],
        'commandes_fournisseurs' => ['Commandes fournisseurs', ['fournisseurs']],
        'ordonnances' => ['Ordonnances', ['ventes', 'clients']],
    ];

    private const DEFAULTS = ['conservation', 'retours', 'depenses', 'rapports_gestion', 'promotions', 'lots_stricts', 'conditionnement', 'commandes_fournisseurs', 'ordonnances'];

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
        $domain = DB::table('domaines_activite')->where('slug', 'pharmacie')->value('id');
        if ($domain === null) {
            return;
        }
        foreach (self::DEFAULTS as $slug) {
            if (isset($ids[$slug]) && ! DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug]])->exists()) {
                DB::table('fonctionnalites_domaine')->insert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug], 'active_par_defaut' => true, 'optionnelle' => false, 'created_at' => $now, 'updated_at' => $now]);
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

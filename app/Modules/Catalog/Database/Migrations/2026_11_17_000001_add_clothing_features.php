<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Boutique de vêtements : nouvelles features `variantes` (tailles, couleurs),
 * `collections` et `promotions` ; fournisseurs, retours, marques et modèles,
 * dépenses et rapport de gestion inclus par défaut. Données pures, idempotente.
 */
return new class extends Migration
{
    private const NEW_FEATURES = [
        'variantes' => ['Tailles et couleurs', ['produits', 'stock']],
        'collections' => ['Collections et saisons', ['produits']],
        'promotions' => ['Promotions et soldes', ['ventes', 'produits']],
    ];

    private const DEFAULTS = ['fournisseurs', 'retours', 'marques_modeles', 'depenses', 'rapports_gestion', 'variantes', 'collections', 'promotions'];

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

        $domain = DB::table('domaines_activite')->where('slug', 'vetements')->value('id');
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

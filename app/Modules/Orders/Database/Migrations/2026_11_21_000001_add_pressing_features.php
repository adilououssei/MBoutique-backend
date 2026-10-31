<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pressing : nouvelle feature `articles_depot` (chaque pièce confiée est
 * décrite, suivie et remise individuellement ; payé et remis séparés). Le
 * domaine reçoit aussi produits, stock, fournisseurs, catégories,
 * dépenses, matériel, rapport de gestion et consommables.
 */
return new class extends Migration
{
    private const DEFAULTS = ['produits', 'categories', 'stock', 'fournisseurs', 'depenses', 'equipements', 'rapports_gestion', 'consommables', 'articles_depot'];

    public function up(): void
    {
        $now = now();
        [$category, $description] = FeatureCatalog::FEATURES['articles_depot'];
        DB::table('fonctionnalites')->insertOrIgnore(['slug' => 'articles_depot', 'nom' => 'Articles déposés', 'categorie' => $category, 'description' => $description, 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        foreach (['commandes', 'clients'] as $dependency) {
            if (isset($ids[$dependency])) {
                DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids['articles_depot'], 'depend_de_fonctionnalite_id' => $ids[$dependency], 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $domain = DB::table('domaines_activite')->where('slug', 'pressing')->value('id');
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
        $id = DB::table('fonctionnalites')->where('slug', 'articles_depot')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('dependances_fonctionnalites')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};

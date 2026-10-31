<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant : nouvelles fonctionnalités (suppléments, recettes, cuisine,
 * réservations) et activation par défaut de celles qui existaient déjà pour
 * d'autres métiers (catégories, fournisseurs, conservation, dépenses,
 * équipements, rapport de gestion). Données pures, idempotente ; les autres
 * métiers ne changent pas.
 */
return new class extends Migration
{
    private const NEW_FEATURES = [
        'supplements' => ['Suppléments', ['commandes', 'produits']],
        'recettes' => ['Recettes', ['stock', 'produits']],
        'cuisine' => ['Cuisine', ['commandes']],
        'reservations' => ['Réservations', ['tables']],
    ];

    private const RESTAURANT_DEFAULTS = [
        'categories', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion',
        'supplements', 'recettes', 'cuisine', 'reservations',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::NEW_FEATURES as $slug => [$name]) {
            [$category, $description] = FeatureCatalog::FEATURES[$slug];
            DB::table('fonctionnalites')->insertOrIgnore([
                'slug' => $slug, 'nom' => $name, 'categorie' => $category, 'description' => $description,
                'actif' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        foreach (self::NEW_FEATURES as $slug => [, $dependencies]) {
            foreach ($dependencies as $dependency) {
                if (isset($ids[$dependency])) {
                    DB::table('dependances_fonctionnalites')->insertOrIgnore([
                        'fonctionnalite_id' => $ids[$slug], 'depend_de_fonctionnalite_id' => $ids[$dependency],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        $restaurant = DB::table('domaines_activite')->where('slug', 'restaurant')->value('id');
        if ($restaurant === null) {
            return;
        }
        foreach (self::RESTAURANT_DEFAULTS as $slug) {
            if (! isset($ids[$slug])) {
                continue;
            }
            $exists = DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $restaurant, 'fonctionnalite_id' => $ids[$slug]])->exists();
            if (! $exists) {
                DB::table('fonctionnalites_domaine')->insert([
                    'domaine_activite_id' => $restaurant, 'fonctionnalite_id' => $ids[$slug],
                    'active_par_defaut' => true, 'optionnelle' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);
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

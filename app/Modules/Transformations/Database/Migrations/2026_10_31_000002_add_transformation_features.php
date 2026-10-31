<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Features `decoupe` et `preparations` (dépendent de `stock` et `produits`),
 * activées par défaut pour le seul domaine Boucherie. Données pures : un autre
 * domaine peut les obtenir par override ou par l'admin plateforme.
 */
return new class extends Migration
{
    private const FEATURES = ['decoupe' => 'Découpe', 'preparations' => 'Préparations'];

    public function up(): void
    {
        $now = now();
        foreach (self::FEATURES as $slug => $name) {
            DB::table('fonctionnalites')->insertOrIgnore(['slug' => $slug, 'nom' => $name, 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $ids = DB::table('fonctionnalites')->whereIn('slug', ['decoupe', 'preparations', 'stock', 'produits'])->pluck('id', 'slug');
        foreach (array_keys(self::FEATURES) as $slug) {
            foreach (['stock', 'produits'] as $dependency) {
                if (isset($ids[$dependency])) {
                    DB::table('dependances_fonctionnalites')->insertOrIgnore([
                        'fonctionnalite_id' => $ids[$slug],
                        'depend_de_fonctionnalite_id' => $ids[$dependency],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        $butcher = DB::table('domaines_activite')->where('slug', 'boucherie')->value('id');
        if ($butcher !== null) {
            foreach (array_keys(self::FEATURES) as $slug) {
                DB::table('fonctionnalites_domaine')->updateOrInsert(
                    ['domaine_activite_id' => $butcher, 'fonctionnalite_id' => $ids[$slug]],
                    ['active_par_defaut' => true, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('fonctionnalites')->whereIn('slug', array_keys(self::FEATURES))->pluck('id');
        DB::table('fonctionnalites_domaine')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('fonctionnalites_boutique')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('dependances_fonctionnalites')->whereIn('fonctionnalite_id', $ids)->delete();
        DB::table('fonctionnalites')->whereIn('id', $ids)->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Feature `conservation` (lots, dates limites, alertes, pertes valorisées),
 * dépend de `stock` et `produits`, active par défaut pour le seul domaine
 * Boucherie. Données pures.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('fonctionnalites')->insertOrIgnore(['slug' => 'conservation', 'nom' => 'Conservation et pertes', 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        $ids = DB::table('fonctionnalites')->whereIn('slug', ['conservation', 'stock', 'produits'])->pluck('id', 'slug');

        foreach (['stock', 'produits'] as $dependency) {
            if (isset($ids[$dependency])) {
                DB::table('dependances_fonctionnalites')->insertOrIgnore([
                    'fonctionnalite_id' => $ids['conservation'],
                    'depend_de_fonctionnalite_id' => $ids[$dependency],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $butcher = DB::table('domaines_activite')->where('slug', 'boucherie')->value('id');
        if ($butcher !== null) {
            DB::table('fonctionnalites_domaine')->updateOrInsert(
                ['domaine_activite_id' => $butcher, 'fonctionnalite_id' => $ids['conservation']],
                ['active_par_defaut' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('fonctionnalites')->where('slug', 'conservation')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('dependances_fonctionnalites')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};

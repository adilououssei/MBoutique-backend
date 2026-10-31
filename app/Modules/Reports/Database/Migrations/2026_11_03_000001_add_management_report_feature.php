<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Feature `rapports_gestion` (dépend de `rapports`), défaut du seul domaine Boucherie. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('fonctionnalites')->insertOrIgnore(['slug' => 'rapports_gestion', 'nom' => 'Rapport de gestion', 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        $ids = DB::table('fonctionnalites')->whereIn('slug', ['rapports_gestion', 'rapports'])->pluck('id', 'slug');

        if (isset($ids['rapports'])) {
            DB::table('dependances_fonctionnalites')->insertOrIgnore([
                'fonctionnalite_id' => $ids['rapports_gestion'],
                'depend_de_fonctionnalite_id' => $ids['rapports'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $butcher = DB::table('domaines_activite')->where('slug', 'boucherie')->value('id');
        if ($butcher !== null) {
            DB::table('fonctionnalites_domaine')->updateOrInsert(
                ['domaine_activite_id' => $butcher, 'fonctionnalite_id' => $ids['rapports_gestion']],
                ['active_par_defaut' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('fonctionnalites')->where('slug', 'rapports_gestion')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('dependances_fonctionnalites')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};

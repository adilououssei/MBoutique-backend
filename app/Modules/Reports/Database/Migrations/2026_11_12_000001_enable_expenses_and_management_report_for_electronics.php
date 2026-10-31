<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Électronique : dépenses et rapport de gestion inclus par défaut (données pures). */
return new class extends Migration
{
    public function up(): void
    {
        $domain = DB::table('domaines_activite')->where('slug', 'electronique')->value('id');
        if ($domain === null) {
            return;
        }
        $now = now();
        foreach (DB::table('fonctionnalites')->whereIn('slug', ['depenses', 'rapports_gestion'])->pluck('id') as $featureId) {
            DB::table('fonctionnalites_domaine')->updateOrInsert(
                ['domaine_activite_id' => $domain, 'fonctionnalite_id' => $featureId],
                ['active_par_defaut' => true, 'optionnelle' => false, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        // Indiscernable d'un réglage fait ensuite par l'admin.
    }
};

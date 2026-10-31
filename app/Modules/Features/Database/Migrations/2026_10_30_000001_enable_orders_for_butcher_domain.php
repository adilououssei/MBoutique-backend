<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Boucherie : les préparations commandées par les clients (avec acompte)
 * passent par le module Orders — la feature `commandes` devient un défaut du
 * domaine. Données pures, aucun autre domaine touché ; une boutique qui l'a
 * explicitement désactivée (override) garde son choix.
 */
return new class extends Migration
{
    public function up(): void
    {
        $domainId = DB::table('domaines_activite')->where('slug', 'boucherie')->value('id');
        $featureId = DB::table('fonctionnalites')->where('slug', 'commandes')->value('id');
        if ($domainId === null || $featureId === null) {
            return; // base vierge : le FeatureSeeder s'en chargera
        }

        DB::table('fonctionnalites_domaine')->updateOrInsert(
            ['domaine_activite_id' => $domainId, 'fonctionnalite_id' => $featureId],
            ['active_par_defaut' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        $domainId = DB::table('domaines_activite')->where('slug', 'boucherie')->value('id');
        $featureId = DB::table('fonctionnalites')->where('slug', 'commandes')->value('id');

        DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $domainId, 'fonctionnalite_id' => $featureId])->delete();
    }
};

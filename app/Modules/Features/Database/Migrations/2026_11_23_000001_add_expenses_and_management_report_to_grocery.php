<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alimentation générale : seul commerce sans dépenses ni rapport de
 * gestion (bénéfice net = marge − dépenses − pertes). Ajout par défaut,
 * idempotent.
 */
return new class extends Migration
{
    private const DEFAULTS = ['depenses', 'rapports_gestion'];

    public function up(): void
    {
        $domain = DB::table('domaines_activite')->where('slug', 'alimentation_generale')->value('id');
        if ($domain === null) {
            return;
        }
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        $now = now();
        foreach (self::DEFAULTS as $slug) {
            if (isset($ids[$slug]) && ! DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug]])->exists()) {
                DB::table('fonctionnalites_domaine')->insert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug], 'active_par_defaut' => true, 'optionnelle' => false, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $domain = DB::table('domaines_activite')->where('slug', 'alimentation_generale')->value('id');
        $ids = DB::table('fonctionnalites')->whereIn('slug', self::DEFAULTS)->pluck('id');
        DB::table('fonctionnalites_domaine')->where('domaine_activite_id', $domain)->whereIn('fonctionnalite_id', $ids)->delete();
    }
};

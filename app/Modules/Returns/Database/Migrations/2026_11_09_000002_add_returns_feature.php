<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Feature `retours` (dépend de `ventes`), défaut du domaine Électronique. Droit : `ventes.annuler`. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('fonctionnalites')->insertOrIgnore(['slug' => 'retours', 'nom' => 'Retours et échanges', 'categorie' => 'commerce', 'description' => 'Retour d’une partie d’une vente : remboursement, avoir ou échange.', 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        if (isset($ids['ventes'])) {
            DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids['retours'], 'depend_de_fonctionnalite_id' => $ids['ventes'], 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($domain = DB::table('domaines_activite')->where('slug', 'electronique')->value('id')) {
            DB::table('fonctionnalites_domaine')->updateOrInsert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids['retours']], ['active_par_defaut' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $id = DB::table('fonctionnalites')->where('slug', 'retours')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('dependances_fonctionnalites')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};

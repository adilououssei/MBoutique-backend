<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Features `marques_modeles` (fiche technique) et `appareils` (IMEI / numéro
 * de série, dépend de `produits` et `stock`), défaut du domaine Électronique.
 */
return new class extends Migration
{
    private const FEATURES = [
        'marques_modeles' => ['Marques et modèles', 'Marque, modèle, état et fiche technique des produits.', ['produits']],
        'appareils' => ['Appareils (IMEI / n° de série)', 'Suivi des appareils à l’unité par IMEI ou numéro de série.', ['produits', 'stock']],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::FEATURES as $slug => [$name, $description]) {
            DB::table('fonctionnalites')->insertOrIgnore(['slug' => $slug, 'nom' => $name, 'categorie' => 'commerce', 'description' => $description, 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        $electronics = DB::table('domaines_activite')->where('slug', 'electronique')->value('id');

        foreach (self::FEATURES as $slug => [, , $dependencies]) {
            foreach ($dependencies as $dependency) {
                if (isset($ids[$dependency])) {
                    DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids[$slug], 'depend_de_fonctionnalite_id' => $ids[$dependency], 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            if ($electronics !== null) {
                DB::table('fonctionnalites_domaine')->updateOrInsert(
                    ['domaine_activite_id' => $electronics, 'fonctionnalite_id' => $ids[$slug]],
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

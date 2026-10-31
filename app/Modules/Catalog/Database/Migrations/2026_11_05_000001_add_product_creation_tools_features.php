<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Création vocale et import Excel deviennent des features (dépendent de
 * `produits`), activées par défaut pour les métiers qui gèrent un vrai
 * catalogue : alimentation générale, vêtements, électronique, boucherie. Les
 * autres n'ont que le formulaire ; l'admin peut ensuite ajuster.
 */
return new class extends Migration
{
    private const FEATURES = [
        'creation_vocale' => ['Création vocale', 'Créer un produit en le dictant.'],
        'import_produits' => ['Import Excel', 'Importer le catalogue depuis un fichier Excel.'],
    ];

    private const DOMAINS = ['alimentation_generale', 'vetements', 'electronique', 'boucherie'];

    public function up(): void
    {
        $now = now();
        foreach (self::FEATURES as $slug => [$name, $description]) {
            DB::table('fonctionnalites')->insertOrIgnore(['slug' => $slug, 'nom' => $name, 'categorie' => 'commerce', 'description' => $description, 'actif' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('fonctionnalites')->whereIn('slug', [...array_keys(self::FEATURES), 'produits'])->pluck('id', 'slug');

        foreach (array_keys(self::FEATURES) as $slug) {
            if (isset($ids['produits'])) {
                DB::table('dependances_fonctionnalites')->insertOrIgnore(['fonctionnalite_id' => $ids[$slug], 'depend_de_fonctionnalite_id' => $ids['produits'], 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (DB::table('domaines_activite')->whereIn('slug', self::DOMAINS)->pluck('id') as $domainId) {
                DB::table('fonctionnalites_domaine')->updateOrInsert(
                    ['domaine_activite_id' => $domainId, 'fonctionnalite_id' => $ids[$slug]],
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

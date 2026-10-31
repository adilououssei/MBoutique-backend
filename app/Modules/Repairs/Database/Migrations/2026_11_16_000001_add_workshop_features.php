<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atelier : dossiers de travaux (feature `reparations`), devis (nouvelle
 * feature `devis`), matériaux et stock, fournisseurs, dépenses, équipements
 * et rapport de gestion inclus par défaut ; fabrication (`preparations`) en
 * option. Données pures, idempotente ; les autres métiers ne changent pas.
 */
return new class extends Migration
{
    private const DEFAULTS = ['produits', 'categories', 'stock', 'fournisseurs', 'depenses', 'equipements', 'reparations', 'devis', 'rapports_gestion'];

    private const OPTIONAL = ['preparations'];

    public function up(): void
    {
        $now = now();
        [$category, $description] = FeatureCatalog::FEATURES['devis'];
        DB::table('fonctionnalites')->insertOrIgnore([
            'slug' => 'devis', 'nom' => 'Devis', 'categorie' => $category, 'description' => $description,
            'actif' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        if (isset($ids['clients'])) {
            DB::table('dependances_fonctionnalites')->insertOrIgnore([
                'fonctionnalite_id' => $ids['devis'], 'depend_de_fonctionnalite_id' => $ids['clients'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $workshop = DB::table('domaines_activite')->where('slug', 'atelier')->value('id');
        if ($workshop === null) {
            return;
        }
        foreach ([...self::DEFAULTS, ...self::OPTIONAL] as $slug) {
            if (! isset($ids[$slug])) {
                continue;
            }
            $optional = in_array($slug, self::OPTIONAL, true);
            $exists = DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $workshop, 'fonctionnalite_id' => $ids[$slug]])->exists();
            if (! $exists) {
                DB::table('fonctionnalites_domaine')->insert([
                    'domaine_activite_id' => $workshop, 'fonctionnalite_id' => $ids[$slug],
                    'active_par_defaut' => ! $optional, 'optionnelle' => $optional, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('fonctionnalites')->where('slug', 'devis')->value('id');
        DB::table('fonctionnalites_domaine')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites_boutique')->where('fonctionnalite_id', $id)->delete();
        DB::table('dependances_fonctionnalites')->where('fonctionnalite_id', $id)->delete();
        DB::table('fonctionnalites')->where('id', $id)->delete();
    }
};

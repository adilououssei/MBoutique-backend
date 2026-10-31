<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coiffure : le domaine reçoit les mêmes outils que le salon de beauté —
 * produits, stock, fournisseurs, catégories, lots, dépenses, matériel,
 * rapport de gestion, promotions, consommables, forfaits, fidélité — et
 * les commissions en option (activées par le gérant). Les features
 * existent déjà (migrations précédentes) : seules les associations au
 * domaine sont ajoutées.
 */
return new class extends Migration
{
    private const DEFAULTS = ['produits', 'categories', 'stock', 'fournisseurs', 'conservation', 'depenses', 'equipements', 'rapports_gestion', 'promotions', 'consommables', 'forfaits', 'fidelite'];

    private const OPTIONAL = ['commissions'];

    public function up(): void
    {
        $domain = DB::table('domaines_activite')->where('slug', 'coiffure')->value('id');
        if ($domain === null) {
            return;
        }
        $now = now();
        $ids = DB::table('fonctionnalites')->pluck('id', 'slug');
        foreach ([...self::DEFAULTS, ...self::OPTIONAL] as $slug) {
            if (isset($ids[$slug]) && ! DB::table('fonctionnalites_domaine')->where(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug]])->exists()) {
                $optional = in_array($slug, self::OPTIONAL, true);
                DB::table('fonctionnalites_domaine')->insert(['domaine_activite_id' => $domain, 'fonctionnalite_id' => $ids[$slug], 'active_par_defaut' => ! $optional, 'optionnelle' => $optional, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $domain = DB::table('domaines_activite')->where('slug', 'coiffure')->value('id');
        $ids = DB::table('fonctionnalites')->whereIn('slug', [...self::DEFAULTS, ...self::OPTIONAL])->pluck('id');
        DB::table('fonctionnalites_domaine')->where('domaine_activite_id', $domain)->whereIn('fonctionnalite_id', $ids)->delete();
    }
};

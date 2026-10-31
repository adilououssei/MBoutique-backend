<?php

use App\Modules\Features\Support\FeatureCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classement des fonctionnalités (à quel métier elles sont destinées) et
 * vocabulaire d'exemple de chaque métier (textes de l'application adaptés à
 * l'activité). Ne remplit que ce qui est vide : une valeur déjà saisie par
 * l'admin n'est jamais écrasée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fonctionnalites', function (Blueprint $table) {
            $table->string('categorie')->nullable()->after('slug');
        });
        Schema::table('domaines_activite', function (Blueprint $table) {
            $table->json('vocabulaire')->nullable()->after('icone');
        });

        foreach (FeatureCatalog::FEATURES as $slug => [$category, $description]) {
            DB::table('fonctionnalites')->where('slug', $slug)->whereNull('categorie')->update(['categorie' => $category]);
            DB::table('fonctionnalites')->where('slug', $slug)->whereNull('description')->update(['description' => $description]);
        }
        foreach (FeatureCatalog::VOCABULARY as $slug => $vocabulary) {
            DB::table('domaines_activite')->where('slug', $slug)->whereNull('vocabulaire')->update(['vocabulaire' => json_encode($vocabulary, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::table('fonctionnalites', fn (Blueprint $table) => $table->dropColumn('categorie'));
        Schema::table('domaines_activite', fn (Blueprint $table) => $table->dropColumn('vocabulaire'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conservation (frais / congelé / transformé) et durée de conservation par
 * défaut d'un produit : la date limite d'un lot qui entre en stock en découle
 * (module Conservation). Facultatifs : les autres domaines n'en ont pas besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('conservation')->nullable()->after('unite');
            $table->unsignedSmallInteger('duree_conservation_jours')->nullable()->after('conservation');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['conservation', 'duree_conservation_jours']);
        });
    }
};

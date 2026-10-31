<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantités proposées à la vente d'un produit au poids (boucherie) :
 * ¼, ½, ¾ kg, 1 kg, 2,5 kg… affichées comme raccourcis à la pesée.
 * Liste JSON de décimales ; vide = raccourcis par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->json('quantites_vente')->nullable()->after('unite');
        });
    }

    public function down(): void
    {
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn('quantites_vente'));
    }
};

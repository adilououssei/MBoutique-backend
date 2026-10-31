<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pertes valorisées : origine (péremption, avarie…) et coût unitaire figé au
 * moment de la perte (prix d'achat du produit). Nullable : les mouvements
 * existants et les autres types n'en ont pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->string('origine_perte')->nullable()->after('motif');
            $table->decimal('cout_unitaire', 12, 2)->nullable()->after('origine_perte');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropColumn(['origine_perte', 'cout_unitaire']);
        });
    }
};

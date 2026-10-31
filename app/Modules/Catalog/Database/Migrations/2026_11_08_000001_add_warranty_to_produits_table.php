<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Garantie offerte à la vente (module Warranties) : durée en mois et conditions, facultatives. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedSmallInteger('garantie_mois')->nullable()->after('suivi_unitaire');
            $table->text('conditions_garantie')->nullable()->after('garantie_mois');
        });
    }

    public function down(): void
    {
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn(['garantie_mois', 'conditions_garantie']));
    }
};

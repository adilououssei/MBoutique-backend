<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pays de l'utilisateur (code ISO à 2 lettres), demandé à l'inscription :
 * statistiques de la plateforme par pays. Vide pour les comptes existants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->char('pays', 2)->nullable()->after('telephone')->index();
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropIndex(['pays']);
            $table->dropColumn('pays');
        });
    }
};

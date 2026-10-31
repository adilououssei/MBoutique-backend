<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacie : un produit rendu « à vérifier » reste en quarantaine (hors
 * stock) jusqu'à la décision du pharmacien : réintégré au stock, ou détruit
 * (perte valorisée). Qui a décidé et quand est conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retours', function (Blueprint $table) {
            // a_verifier, reintegre, detruit (null : retour ordinaire)
            $table->string('statut_verification')->nullable()->after('etat_produits');
            $table->foreignId('verifie_par_id')->nullable()->after('statut_verification')->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('verifie_le')->nullable()->after('verifie_par_id');
            $table->string('note_verification', 500)->nullable()->after('verifie_le');
        });
    }

    public function down(): void
    {
        Schema::table('retours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verifie_par_id');
            $table->dropColumn(['statut_verification', 'verifie_le', 'note_verification']);
        });
    }
};

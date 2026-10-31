<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche technique (électronique) : marque, modèle, état, caractéristiques,
 * fournisseur habituel, et suivi à l'unité (IMEI / numéro de série, module
 * Devices). Tout est facultatif : un accessoire n'a ni modèle ni IMEI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('marque')->nullable()->after('nom');
            $table->string('modele')->nullable()->after('marque');
            $table->string('etat')->nullable()->after('modele');
            $table->text('caracteristiques')->nullable()->after('description');
            $table->foreignId('fournisseur_id')->nullable()->after('categorie_id')->constrained('fournisseurs')->nullOnDelete();
            $table->boolean('suivi_unitaire')->default(false)->after('unite');

            $table->index(['boutique_id', 'marque']);
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropIndex(['boutique_id', 'marque']);
            $table->dropConstrainedForeignId('fournisseur_id');
            $table->dropColumn(['marque', 'modele', 'etat', 'caracteristiques', 'suivi_unitaire']);
        });
    }
};

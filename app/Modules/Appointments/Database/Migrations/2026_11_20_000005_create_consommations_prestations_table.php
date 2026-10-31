<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace des produits consommés par les prestations d'une vente : quantité
 * prévue (fiche de la prestation) et réellement sortie du stock (ajustée à
 * l'encaissement par une personne habilitée). Une ligne par produit et par
 * vente : la sortie de stock a lieu une seule fois, avec la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consommations_prestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits');
            $table->decimal('quantite_prevue', 12, 3)->default(0);
            $table->decimal('quantite', 12, 3);
            $table->decimal('cout_unitaire', 12, 2)->nullable();
            $table->boolean('ajustee')->default(false);
            $table->timestamps();

            $table->unique(['vente_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consommations_prestations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transferts de stock entre deux boutiques d'une même entreprise —
 * docs/inventory.md §"Transferts". Un transfert appartient aux deux
 * boutiques à la fois : pas de `boutique_id` unique, donc pas de
 * BelongsToStore ; le cloisonnement se fait par source/destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferts_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boutique_source_id')->constrained('boutiques')->cascadeOnDelete();
            $table->foreignId('boutique_destination_id')->constrained('boutiques')->cascadeOnDelete();
            $table->string('reference')->nullable()->unique();
            $table->string('note', 500)->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['boutique_source_id', 'created_at']);
            $table->index(['boutique_destination_id', 'created_at']);
        });

        Schema::create('lignes_transfert_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfert_id')->constrained('transferts_stock')->cascadeOnDelete();
            $table->foreignId('produit_source_id')->constrained('produits')->restrictOnDelete();
            $table->foreignId('produit_destination_id')->constrained('produits')->restrictOnDelete();
            // Nom figé au moment du transfert, comme sur les lignes de vente.
            $table->string('nom_produit');
            $table->decimal('quantite', 12, 3);
            // Le produit a-t-il été créé dans la boutique de destination par ce transfert ?
            $table->boolean('produit_cree')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_transfert_stock');
        Schema::dropIfExists('transferts_stock');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recette d'un plat (restaurant) : ingrédients et quantités pour `portions`
 * portions. À la vente, les ingrédients sortent du stock à la place du plat.
 * Distinct des fiches de préparation (boucherie), qui produisent du stock
 * par fournée : ici rien n'est produit d'avance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->unique()->constrained('produits')->cascadeOnDelete();
            $table->decimal('portions', 8, 2)->default(1);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('ingredients_recette', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recette_id')->constrained('recettes')->cascadeOnDelete();
            // Quantité dans l'unité de l'ingrédient (kg, g, l, pièce…).
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->decimal('quantite', 12, 3);
            $table->timestamps();

            $table->unique(['recette_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients_recette');
        Schema::dropIfExists('recettes');
    }
};

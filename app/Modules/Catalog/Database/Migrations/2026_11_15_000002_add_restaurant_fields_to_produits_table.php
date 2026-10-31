<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carte d'un restaurant : nature de l'article (plat, boisson, ingrédient,
 * formule), temps de préparation, disponibilité du jour (« épuisé », distinct
 * de actif) et suppléments payants proposés avec un plat. Tout est facultatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('type_article')->nullable()->after('unite');
            $table->unsignedSmallInteger('temps_preparation_minutes')->nullable()->after('type_article');
            $table->boolean('disponible')->default(true)->after('actif');
        });

        Schema::create('supplements_produit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            // Le supplément est lui-même un produit : prix, stock et recette suivent.
            $table->foreignId('supplement_id')->constrained('produits')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['produit_id', 'supplement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplements_produit');
        Schema::table('produits', fn (Blueprint $table) => $table->dropColumn(['type_article', 'temps_preparation_minutes', 'disponible']));
    }
};

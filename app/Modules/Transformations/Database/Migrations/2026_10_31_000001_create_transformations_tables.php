<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transformations du stock — docs/modules.md §Transformations : découpe d'une
 * carcasse en morceaux, préparation (haché, saucisses, brochettes…) à partir
 * d'une fiche. Des matières sont consommées, des produits sont obtenus ; le
 * coût des matières est réparti sur les produits obtenus. Jamais un achat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiches_preparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            // Le produit fini que la fiche permet de fabriquer.
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('nom');
            // Quantité de produit fini obtenue pour une fournée (un « lot »).
            $table->decimal('quantite_produite', 12, 3);
            $table->unsignedSmallInteger('duree_conservation_jours')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('produit_id');
        });

        Schema::create('ingredients_fiche', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiche_preparation_id')->constrained('fiches_preparation')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            // Quantité consommée pour une fournée.
            $table->decimal('quantite', 12, 3);
            $table->timestamps();

            $table->index('fiche_preparation_id');
        });

        Schema::create('transformations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // decoupe | preparation
            $table->string('reference')->nullable();
            $table->string('statut')->default('terminee'); // terminee | annulee
            $table->foreignId('fiche_preparation_id')->nullable()->constrained('fiches_preparation')->nullOnDelete();
            // Coût des matières consommées, plus les frais hors stock
            // (épices, emballage, main-d'œuvre…) : c'est ce qui est réparti.
            $table->decimal('cout_matieres', 12, 2);
            $table->decimal('frais_annexes', 12, 2)->default(0);
            $table->decimal('cout_total', 12, 2);
            // Poids (kg) des lignes vendues au poids ; la perte est l'écart.
            $table->decimal('poids_entree', 12, 3)->default(0);
            $table->decimal('poids_sortie', 12, 3)->default(0);
            $table->decimal('perte', 12, 3)->default(0);
            $table->string('methode_repartition'); // valeur | poids
            $table->date('consommer_avant')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('effectuee_le');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('annulee_le')->nullable();
            $table->foreignId('annulee_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('motif_annulation', 500)->nullable();
            $table->string('cle_idempotence')->nullable();
            $table->timestamps();

            $table->unique(['boutique_id', 'reference']);
            $table->unique(['boutique_id', 'cle_idempotence']);
            $table->index(['type', 'effectuee_le']);
        });

        Schema::create('lignes_transformation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transformation_id')->constrained('transformations')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('nom_produit');
            $table->string('unite');
            $table->string('sens'); // consommee | obtenue
            $table->decimal('quantite', 12, 3);
            $table->decimal('cout_unitaire', 12, 2);
            $table->decimal('cout_total', 12, 2);
            // Prix de vente au moment de l'opération (marge potentielle), lignes obtenues seulement.
            $table->decimal('prix_vente_unitaire', 12, 2)->nullable();
            $table->timestamps();

            $table->index('transformation_id');
            $table->index('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_transformation');
        Schema::dropIfExists('transformations');
        Schema::dropIfExists('ingredients_fiche');
        Schema::dropIfExists('fiches_preparation');
    }
};

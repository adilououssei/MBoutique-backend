<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions et soldes : remise en % ou en montant, datée, sur toute la
 * boutique, des articles, une catégorie ou une collection. Appliquée par
 * SaleService à la vente ; la ligne de vente garde la promotion utilisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            // pourcentage, montant (par unité)
            $table->string('type');
            $table->decimal('valeur', 12, 2);
            $table->timestamp('debut_le');
            $table->timestamp('fin_le')->nullable();
            // tous, produits, categorie, collection
            $table->string('portee')->default('tous');
            $table->foreignId('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->boolean('actif')->default(true);
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['boutique_id', 'actif', 'debut_le']);
        });

        Schema::create('produits_promotion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['promotion_id', 'produit_id']);
        });

        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('montant_remise')->constrained('promotions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lignes_vente', fn (Blueprint $table) => $table->dropConstrainedForeignId('promotion_id'));
        Schema::dropIfExists('produits_promotion');
        Schema::dropIfExists('promotions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vêtements : sous-catégories, collections, public cible et variantes.
 * Une variante est un produit rattaché à son article (`produit_parent_id`)
 * avec sa taille et sa couleur : stock, prix, code-barres, achats, ventes,
 * retours et transferts fonctionnent par variante sans rien changer d'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('boutique_id')->constrained('categories')->nullOnDelete();
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            // ete, hiver, rentree, fetes, traditionnelle, autre
            $table->string('saison')->nullable();
            $table->date('debut_le')->nullable();
            $table->date('fin_le')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->foreignId('produit_parent_id')->nullable()->after('categorie_id')->constrained('produits')->nullOnDelete();
            $table->string('taille', 30)->nullable()->after('modele');
            $table->string('couleur', 50)->nullable()->after('taille');
            // homme, femme, enfant, bebe, mixte
            $table->string('public_cible')->nullable()->after('couleur');
            $table->foreignId('collection_id')->nullable()->after('public_cible')->constrained('collections')->nullOnDelete();
            $table->index(['boutique_id', 'produit_parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropIndex(['boutique_id', 'produit_parent_id']);
            $table->dropConstrainedForeignId('collection_id');
            $table->dropConstrainedForeignId('produit_parent_id');
            $table->dropColumn(['taille', 'couleur', 'public_cible']);
        });
        Schema::dropIfExists('collections');
        Schema::table('categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_id'));
    }
};

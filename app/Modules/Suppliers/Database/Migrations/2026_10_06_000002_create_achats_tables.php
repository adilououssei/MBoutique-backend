<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achats (réceptions de marchandise) et leurs règlements — docs/modules.md §Suppliers.
 * Même esprit que Sales : lignes figées (snapshot), montants en DECIMAL,
 * historique jamais supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            // Nullable : un achat au marché n'a pas toujours de fournisseur enregistré.
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->decimal('montant_total', 12, 2);
            // Cumul des règlements (dénormalisé, mis à jour sous verrou par PurchaseService).
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('cle_idempotence')->nullable();
            $table->timestamp('achete_le');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'reference']);
            $table->unique(['boutique_id', 'cle_idempotence']);
            $table->index('achete_le');
        });

        Schema::create('lignes_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achat_id')->constrained('achats')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('nom_produit');
            $table->decimal('quantite', 12, 3);
            $table->decimal('cout_unitaire', 12, 2);
            $table->decimal('montant_total', 12, 2);
            $table->timestamps();

            $table->index('achat_id');
            $table->index('produit_id');
        });

        Schema::create('paiements_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('achat_id')->constrained('achats')->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            // `caisse` : sorti d'une session de caisse ouverte (mouvement de sortie) ;
            // `externe` : payé hors caisse (banque, Mobile Money, poche du gérant…).
            $table->string('mode');
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('paye_le');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index('achat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_achat');
        Schema::dropIfExists('lignes_achat');
        Schema::dropIfExists('achats');
    }
};

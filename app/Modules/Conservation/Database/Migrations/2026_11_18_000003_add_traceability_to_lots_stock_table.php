<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacie : identité complète d'un lot (n° du fabricant, fabrication,
 * prix, fournisseur, achat d'origine), statut `actif` / `bloque` / `rappele`,
 * journal des actions sensibles, et lots sortis par chaque ligne de vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots_stock', function (Blueprint $table) {
            $table->string('numero_lot')->nullable()->after('numero');
            $table->date('fabrique_le')->nullable()->after('recu_le');
            $table->decimal('prix_achat', 12, 2)->nullable()->after('quantite_restante');
            $table->foreignId('fournisseur_id')->nullable()->after('produit_id')->constrained('fournisseurs')->nullOnDelete();
            $table->foreignId('achat_id')->nullable()->after('fournisseur_id')->constrained('achats')->nullOnDelete();
            $table->string('statut')->default('actif')->after('date_limite');
            $table->string('motif_blocage', 500)->nullable()->after('statut');
            $table->index(['produit_id', 'statut']);
        });

        Schema::create('journal_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('lots_stock')->cascadeOnDelete();
            // creation, modification, blocage, rappel, deblocage, perte, vente, retour
            $table->string('action');
            $table->text('details')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lots_vente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ligne_vente_id')->constrained('lignes_vente')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('lots_stock')->restrictOnDelete();
            $table->decimal('quantite', 12, 3);
            $table->timestamps();
        });

        Schema::table('lignes_achat', function (Blueprint $table) {
            $table->string('numero_lot')->nullable()->after('montant_total');
            $table->date('date_peremption')->nullable()->after('numero_lot');
            $table->date('fabrique_le')->nullable()->after('date_peremption');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_achat', fn (Blueprint $table) => $table->dropColumn(['numero_lot', 'date_peremption', 'fabrique_le']));
        Schema::dropIfExists('lots_vente');
        Schema::dropIfExists('journal_lots');
        Schema::table('lots_stock', function (Blueprint $table) {
            $table->dropIndex(['produit_id', 'statut']);
            $table->dropConstrainedForeignId('achat_id');
            $table->dropConstrainedForeignId('fournisseur_id');
            $table->dropColumn(['numero_lot', 'fabrique_le', 'prix_achat', 'statut', 'motif_blocage']);
        });
    }
};

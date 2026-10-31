<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retours et échanges, toujours rattachés à la vente d'origine —
 * docs/modules.md §Returns. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero')->nullable();
            $table->foreignId('vente_id')->constrained('ventes')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('motif', 500);
            $table->string('decision'); // remboursement | avoir
            $table->string('etat_produits'); // revendable | defectueux
            $table->decimal('montant', 12, 2);
            $table->string('mode_remboursement')->nullable(); // especes | hors_caisse
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->restrictOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'numero']);
            $table->index('vente_id');
        });

        Schema::create('lignes_retour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retour_id')->constrained('retours')->cascadeOnDelete();
            $table->foreignId('ligne_vente_id')->constrained('lignes_vente')->restrictOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('nom_produit');
            $table->decimal('quantite', 12, 3);
            $table->decimal('montant', 12, 2);
            $table->json('appareil_ids')->nullable();
            $table->timestamps();

            $table->index('ligne_vente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_retour');
        Schema::dropIfExists('retours');
    }
};

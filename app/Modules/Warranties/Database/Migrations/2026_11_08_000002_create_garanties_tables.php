<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garanties créées à la vente (une par appareil identifié, sinon une par
 * ligne), réclamations et leur suivi — docs/modules.md §Warranties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garanties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero')->nullable();
            $table->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $table->foreignId('ligne_vente_id')->nullable()->constrained('lignes_vente')->nullOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->foreignId('appareil_id')->nullable()->constrained('appareils')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_produit');
            $table->decimal('quantite', 12, 3)->default(1);
            $table->unsignedSmallInteger('duree_mois');
            $table->date('debut_le');
            $table->date('fin_le');
            $table->text('conditions')->nullable();
            $table->string('statut')->default('valide'); // valide | annulee
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();

            $table->unique(['boutique_id', 'numero']);
            $table->index('fin_le');
        });

        Schema::create('reclamations_garantie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('garantie_id')->constrained('garanties')->cascadeOnDelete();
            $table->string('numero')->nullable();
            $table->text('description');
            $table->string('statut')->default('ouverte'); // ouverte | en_cours | resolue | refusee
            $table->text('resolution')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('cloturee_le')->nullable();
            $table->timestamps();

            $table->index(['garantie_id', 'statut']);
        });

        Schema::create('suivis_reclamation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reclamation_id')->constrained('reclamations_garantie')->cascadeOnDelete();
            $table->string('statut');
            $table->string('note', 1000)->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suivis_reclamation');
        Schema::dropIfExists('reclamations_garantie');
        Schema::dropIfExists('garanties');
    }
};

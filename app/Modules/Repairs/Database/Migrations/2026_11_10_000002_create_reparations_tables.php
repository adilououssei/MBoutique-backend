<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Réparations d'appareils déposés par les clients — docs/modules.md §Repairs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_client')->nullable();
            $table->string('telephone_client', 30)->nullable();
            $table->foreignId('appareil_id')->nullable()->constrained('appareils')->nullOnDelete();
            $table->string('appareil'); // « iPhone 11 noir », « HP ProBook »…
            $table->string('marque')->nullable();
            $table->string('modele')->nullable();
            $table->string('imei')->nullable();
            $table->string('numero_serie')->nullable();
            $table->text('panne');
            $table->text('etat_reception')->nullable();
            $table->text('diagnostic')->nullable();
            $table->foreignId('technicien_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->decimal('cout_estime', 12, 2)->nullable();
            $table->decimal('cout_final', 12, 2)->nullable();
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->string('statut')->default('recu');
            $table->timestamp('depose_le');
            $table->date('prevu_le')->nullable();
            $table->timestamp('livre_le')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'numero']);
            $table->index(['statut', 'depose_le']);
        });

        Schema::create('pieces_reparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reparation_id')->constrained('reparations')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('nom_produit');
            $table->decimal('quantite', 12, 3);
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('montant', 12, 2);
            $table->timestamps();
        });

        Schema::create('paiements_reparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reparation_id')->constrained('reparations')->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->string('mode'); // especes | mobile_money | carte | virement
            $table->foreignId('session_caisse_id')->nullable()->constrained('sessions_caisse')->restrictOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('suivis_reparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reparation_id')->constrained('reparations')->cascadeOnDelete();
            $table->string('statut');
            $table->string('note', 1000)->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suivis_reparation');
        Schema::dropIfExists('paiements_reparation');
        Schema::dropIfExists('pieces_reparation');
        Schema::dropIfExists('reparations');
    }
};

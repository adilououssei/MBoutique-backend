<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atelier : devis (lignes prestations, matériaux, main-d'œuvre, frais) et
 * dossiers de travaux enrichis — bien confié selon la spécialité, photos,
 * prestations facturées, plusieurs techniciens, matériaux non refacturés,
 * livraison distincte du paiement (reste au compte du client). Ajouts seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_client')->nullable();
            $table->string('telephone_client', 30)->nullable();
            $table->string('objet');
            $table->text('description')->nullable();
            $table->date('valide_jusqu_au')->nullable();
            // brouillon, envoye, accepte, refuse, transforme (expiré : calculé)
            $table->string('statut')->default('brouillon');
            $table->decimal('montant_remise', 12, 2)->default(0);
            $table->decimal('montant_total', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('reparation_id')->nullable()->constrained('reparations')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'numero']);
            $table->index(['boutique_id', 'statut']);
        });

        Schema::create('lignes_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            // prestation, materiau, main_oeuvre, frais
            $table->string('type');
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('libelle');
            $table->decimal('quantite', 12, 3);
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('montant', 12, 2);
            $table->timestamps();
        });

        Schema::table('reparations', function (Blueprint $table) {
            // Caractéristiques du bien selon la spécialité (immatriculation, kilométrage, dimensions…).
            $table->json('bien')->nullable()->after('numero_serie');
            $table->json('photos')->nullable()->after('bien');
            $table->foreignId('devis_id')->nullable()->after('appareil_id')->constrained('devis')->nullOnDelete();
            // Reste dû passé au compte du client à la livraison.
            $table->decimal('montant_credit', 12, 2)->default(0)->after('montant_paye');
            $table->foreignId('livre_par_id')->nullable()->after('livre_le')->constrained('utilisateurs')->nullOnDelete();
            $table->string('remis_a')->nullable()->after('livre_par_id');
            $table->boolean('reception_confirmee')->default(false)->after('remis_a');
        });

        Schema::create('prestations_reparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reparation_id')->constrained('reparations')->cascadeOnDelete();
            // prestation, materiau, main_oeuvre, frais, remise
            $table->string('type')->default('prestation');
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('libelle');
            $table->decimal('quantite', 12, 3);
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('montant', 12, 2);
            $table->timestamps();
        });

        Schema::create('techniciens_reparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reparation_id')->constrained('reparations')->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained('employes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reparation_id', 'employe_id']);
        });

        Schema::table('pieces_reparation', function (Blueprint $table) {
            // false : matériau déjà compris dans le prix (devis, forfait) — coût suivi, non refacturé.
            $table->boolean('facturee')->default(true)->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('pieces_reparation', fn (Blueprint $table) => $table->dropColumn('facturee'));
        Schema::dropIfExists('techniciens_reparation');
        Schema::dropIfExists('prestations_reparation');
        Schema::table('reparations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('devis_id');
            $table->dropConstrainedForeignId('livre_par_id');
            $table->dropColumn(['bien', 'photos', 'montant_credit', 'remis_a', 'reception_confirmee']);
        });
        Schema::dropIfExists('lignes_devis');
        Schema::dropIfExists('devis');
    }
};

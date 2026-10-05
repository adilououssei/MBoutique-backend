<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes et tables — docs/database.md §13, docs/modules.md §Orders.
 * Le statut libre/occupée d'une table n'est pas stocké : il est dérivé des
 * commandes ouvertes (une seule source de vérité).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_salle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->unsignedSmallInteger('capacite')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->nullable();
            // sur_place, a_emporter, livraison, depot
            $table->string('type');
            // en_attente, en_preparation, prete, servie, payee, annulee
            $table->string('statut');
            $table->foreignId('table_id')->nullable()->constrained('tables_salle')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_client')->nullable();
            $table->string('telephone_client', 30)->nullable();
            $table->string('adresse_livraison')->nullable();
            // Atelier/pressing : date promise de retrait.
            $table->timestamp('date_promise')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $table->string('motif_annulation', 500)->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boutique_id', 'reference']);
            $table->index(['boutique_id', 'statut']);
            $table->index('table_id');
        });

        Schema::create('lignes_commande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            // Un produit (avec mode de prix) OU un service, comme une ligne de vente.
            $table->foreignId('produit_id')->nullable()->constrained('produits')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->string('mode_prix')->nullable();
            $table->string('nom');
            $table->decimal('quantite', 12, 3);
            // Prix indicatif au moment de la commande ; le prix encaissé est
            // recalculé par Sales au paiement (jamais celui du client).
            $table->decimal('prix_unitaire', 12, 2);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('commande_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_commande');
        Schema::dropIfExists('commandes');
        Schema::dropIfExists('tables_salle');
    }
};

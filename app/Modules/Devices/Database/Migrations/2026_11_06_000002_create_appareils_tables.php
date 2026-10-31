<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appareils suivis à l'unité (téléphones, ordinateurs…) : chaque exemplaire
 * d'un produit `suivi_unitaire`, identifié par IMEI et/ou numéro de série,
 * avec son historique — docs/modules.md §Devices. La quantité en stock reste
 * tenue par Inventory ; l'appareil dit lequel est vendu, à qui, quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('imei')->nullable();
            $table->string('imei_2')->nullable();
            $table->string('numero_serie')->nullable();
            $table->string('statut')->default('en_stock');
            $table->string('etat')->nullable(); // neuf | occasion | reconditionne
            $table->decimal('cout_achat', 12, 2)->nullable();
            $table->foreignId('ligne_achat_id')->nullable()->constrained('lignes_achat')->nullOnDelete();
            $table->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $table->foreignId('ligne_vente_id')->nullable()->constrained('lignes_vente')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('vendu_le')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['boutique_id', 'imei']);
            $table->unique(['boutique_id', 'numero_serie']);
            $table->index(['produit_id', 'statut']);
        });

        Schema::create('historique_appareils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appareil_id')->constrained('appareils')->cascadeOnDelete();
            // entree, vente, annulation_vente, defectueux, perdu, remis_en_stock, modification
            $table->string('evenement');
            $table->string('description', 500)->nullable();
            $table->nullableMorphs('reference');
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['appareil_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_appareils');
        Schema::dropIfExists('appareils');
    }
};

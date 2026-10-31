<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pressing : articles déposés (une ligne par pièce, code unique), remises
 * (totales ou partielles), historique des statuts, responsable, livraison,
 * consommation des produits au passage « prête » (une fois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignId('responsable_id')->nullable()->after('serveur_id')->constrained('employes')->nullOnDelete();
            // a_livrer | en_livraison | livree
            $table->string('statut_livraison', 20)->nullable()->after('adresse_livraison');
            $table->foreignId('livreur_id')->nullable()->after('statut_livraison')->constrained('employes')->nullOnDelete();
            $table->timestamp('livree_le')->nullable()->after('livreur_id');
            $table->timestamp('remise_le')->nullable()->after('date_promise');
            $table->foreignId('remise_par_id')->nullable()->after('remise_le')->constrained('utilisateurs')->nullOnDelete();
            $table->boolean('consommation_faite')->default(false)->after('remise_par_id');
        });

        Schema::create('remises_depot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            // retrait | livraison
            $table->string('mode', 20)->default('retrait');
            $table->unsignedInteger('nombre_articles');
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('remis_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('remis_le');
            $table->timestamps();
        });

        Schema::create('articles_depot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('ligne_commande_id')->nullable()->constrained('lignes_commande')->nullOnDelete();
            $table->string('code', 30);
            $table->string('type_article', 100)->nullable();
            $table->string('couleur', 100)->nullable();
            $table->string('marque', 100)->nullable();
            $table->string('etat', 255)->nullable();
            $table->string('defauts', 500)->nullable();
            $table->string('instructions', 500)->nullable();
            $table->string('photo')->nullable();
            // recu | en_traitement | controle | pret | remis | annule
            $table->string('statut', 20)->default('recu');
            $table->foreignId('employe_id')->nullable()->constrained('employes')->nullOnDelete();
            $table->foreignId('remise_id')->nullable()->constrained('remises_depot')->nullOnDelete();
            $table->timestamp('remis_le')->nullable();
            $table->timestamps();

            $table->unique(['boutique_id', 'code']);
            $table->index(['commande_id', 'statut']);
        });

        Schema::create('historique_commandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commande_id')->constrained('commandes')->cascadeOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles_depot')->nullOnDelete();
            // statut | remise | paiement | livraison | affectation | consommation
            $table->string('evenement', 20);
            $table->string('de', 30)->nullable();
            $table->string('vers', 30)->nullable();
            // paiement : somme réellement encaissée (acompte, solde)
            $table->decimal('montant', 12, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['commande_id', 'created_at']);
        });

        // La consommation d'un dépôt est tracée sur la commande (au passage « prête »), pas sur une vente.
        Schema::table('consommations_prestations', function (Blueprint $table) {
            $table->foreignId('commande_id')->nullable()->after('vente_id')->constrained('commandes')->cascadeOnDelete();
        });
        Schema::table('consommations_prestations', function (Blueprint $table) {
            $table->foreignId('vente_id')->nullable()->change();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('instructions_remise', 500)->nullable()->after('adresse');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('instructions_remise'));
        Schema::table('consommations_prestations', fn (Blueprint $table) => $table->dropConstrainedForeignId('commande_id'));
        Schema::dropIfExists('historique_commandes');
        Schema::dropIfExists('articles_depot');
        Schema::dropIfExists('remises_depot');
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_id');
            $table->dropConstrainedForeignId('livreur_id');
            $table->dropConstrainedForeignId('remise_par_id');
            $table->dropColumn(['statut_livraison', 'livree_le', 'remise_le', 'consommation_faite']);
        });
    }
};

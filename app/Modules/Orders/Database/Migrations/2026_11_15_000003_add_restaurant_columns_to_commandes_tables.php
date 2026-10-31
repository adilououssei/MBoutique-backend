<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant : suppléments rattachés à leur plat, suivi cuisine par ligne,
 * serveur et couverts de la commande, addition demandée (table en attente
 * de paiement), espace de salle et nettoyage des tables. Ajouts seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->foreignId('ligne_parent_id')->nullable()->after('commande_id')->constrained('lignes_commande')->cascadeOnDelete();
            // a_preparer, en_preparation, prete, servie, indisponible
            $table->string('statut_cuisine')->nullable()->after('note');
            $table->timestamp('preparation_commencee_le')->nullable()->after('statut_cuisine');
            $table->timestamp('prete_le')->nullable()->after('preparation_commencee_le');
            $table->timestamp('servie_le')->nullable()->after('prete_le');
            $table->index(['boutique_id', 'statut_cuisine']);
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignId('serveur_id')->nullable()->after('cree_par_id')->constrained('utilisateurs')->nullOnDelete();
            $table->unsignedSmallInteger('nombre_couverts')->nullable()->after('table_id');
            $table->timestamp('addition_demandee_le')->nullable()->after('note');
        });

        Schema::table('tables_salle', function (Blueprint $table) {
            $table->string('espace', 100)->nullable()->after('nom');
            $table->boolean('a_nettoyer')->default(false)->after('actif');
        });
    }

    public function down(): void
    {
        Schema::table('tables_salle', fn (Blueprint $table) => $table->dropColumn(['espace', 'a_nettoyer']));
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serveur_id');
            $table->dropColumn(['nombre_couverts', 'addition_demandee_le']);
        });
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->dropIndex(['boutique_id', 'statut_cuisine']);
            $table->dropConstrainedForeignId('ligne_parent_id');
            $table->dropColumn(['statut_cuisine', 'preparation_commencee_le', 'prete_le', 'servie_le']);
        });
    }
};

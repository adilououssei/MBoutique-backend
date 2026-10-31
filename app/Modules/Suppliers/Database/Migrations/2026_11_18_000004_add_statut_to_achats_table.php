<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande fournisseur (`commande`) distincte de la réception (`recu`) : une
 * commande n'ajoute pas de stock et ne crée pas de dette ; la réception
 * vérifiée (quantités, lots, péremptions) fait les deux. Existants : `recu`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achats', function (Blueprint $table) {
            $table->string('statut')->default('recu')->after('reference_commande');
            $table->timestamp('commande_le')->nullable()->after('achete_le');
            $table->foreignId('recu_par_id')->nullable()->after('cree_par_id')->constrained('utilisateurs')->nullOnDelete();
            $table->decimal('remise_fournisseur', 12, 2)->default(0)->after('frais_annexes');
        });
        Schema::table('lignes_achat', function (Blueprint $table) {
            $table->decimal('quantite_commandee', 12, 3)->nullable()->after('quantite');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_achat', fn (Blueprint $table) => $table->dropColumn('quantite_commandee'));
        Schema::table('achats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recu_par_id');
            $table->dropColumn(['statut', 'commande_le', 'remise_fournisseur']);
        });
    }
};

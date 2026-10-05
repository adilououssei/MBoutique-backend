<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point 1 de l'après-Phase 4.3 (docs/sales.md §20-22) :
 * - annulation d'une vente : qui, quand, pourquoi, et depuis quelle
 *   session de caisse le remboursement a été sorti ;
 * - vente de services : une ligne porte un produit OU un service ;
 * - remise par ligne, en plus de la remise globale existante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->timestamp('annulee_le')->nullable()->after('vendue_le');
            $table->foreignId('annulee_par_id')->nullable()->after('annulee_le')->constrained('utilisateurs')->nullOnDelete();
            $table->string('motif_annulation', 500)->nullable()->after('annulee_par_id');
            $table->foreignId('session_remboursement_id')->nullable()->after('motif_annulation')->constrained('sessions_caisse')->restrictOnDelete();
        });

        Schema::table('lignes_vente', function (Blueprint $table) {
            // Une ligne de service n'a ni produit ni mode de prix (détail/gros
            // n'existe que pour les produits). Exactement l'un des deux
            // identifiants est renseigné — garanti par la validation et par
            // SaleService, seul point d'écriture.
            $table->foreignId('produit_id')->nullable()->change();
            $table->string('mode_prix')->nullable()->change();
            $table->foreignId('service_id')->nullable()->after('produit_id')->constrained()->restrictOnDelete();
            $table->decimal('montant_remise', 12, 2)->default(0)->after('quantite');
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropIndex(['service_id']);
            $table->dropColumn(['service_id', 'montant_remise']);
        });

        Schema::table('ventes', function (Blueprint $table) {
            $table->dropForeign(['annulee_par_id']);
            $table->dropForeign(['session_remboursement_id']);
            $table->dropColumn(['annulee_le', 'annulee_par_id', 'motif_annulation', 'session_remboursement_id']);
        });
    }
};

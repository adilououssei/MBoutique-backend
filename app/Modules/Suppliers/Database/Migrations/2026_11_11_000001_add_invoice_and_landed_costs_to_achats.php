<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achats : n° de facture et de commande du fournisseur, frais annexes
 * (transport, douane, livraison) répartis dans le coût de revient des lignes.
 * Les frais ne s'ajoutent pas à la dette fournisseur (ils sont payés à d'autres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achats', function (Blueprint $table) {
            $table->string('numero_facture_fournisseur')->nullable()->after('reference');
            $table->string('reference_commande')->nullable()->after('numero_facture_fournisseur');
            $table->decimal('frais_annexes', 12, 2)->default(0)->after('montant_paye');
            $table->string('detail_frais', 500)->nullable()->after('frais_annexes');
        });
        Schema::table('lignes_achat', function (Blueprint $table) {
            $table->decimal('cout_revient_unitaire', 12, 2)->nullable()->after('cout_unitaire');
        });
    }

    public function down(): void
    {
        Schema::table('achats', fn (Blueprint $table) => $table->dropColumn(['numero_facture_fournisseur', 'reference_commande', 'frais_annexes', 'detail_frais']));
        Schema::table('lignes_achat', fn (Blueprint $table) => $table->dropColumn('cout_revient_unitaire'));
    }
};

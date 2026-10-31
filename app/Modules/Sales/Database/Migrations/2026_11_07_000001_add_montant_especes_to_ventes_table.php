<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espèces réellement entrées dans la caisse pour la vente (0 pour un
 * paiement Mobile Money, carte ou virement). L'annulation ne rembourse en
 * caisse que ce montant. Null pour les ventes antérieures : règle d'avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->decimal('montant_especes', 12, 2)->nullable()->after('montant_acompte');
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('montant_especes');
        });
    }
};

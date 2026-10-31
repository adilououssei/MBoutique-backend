<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Addition divisée : une ligne déjà réglée par une vente partielle porte cette vente. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande', function (Blueprint $table) {
            $table->foreignId('vente_id')->nullable()->after('ligne_parent_id')->constrained('ventes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande', fn (Blueprint $table) => $table->dropConstrainedForeignId('vente_id'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dépense hors caisse : moyen utilisé (espèces hors caisse, Mobile Money,
 * carte, virement, chèque). Justificatif photographié, sur disque privé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->string('moyen_paiement')->nullable()->after('mode');
            $table->string('justificatif')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('depenses', fn (Blueprint $table) => $table->dropColumn(['moyen_paiement', 'justificatif']));
    }
};

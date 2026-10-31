<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a créé la caisse : lui seul peut la modifier, et chacun n'a qu'une
 * caisse active par boutique. Vide pour les caisses existantes (modifiables
 * par qui a caisse.gerer, comme avant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->foreignId('cree_par_id')->nullable()->after('actif')->constrained('utilisateurs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cree_par_id');
        });
    }
};

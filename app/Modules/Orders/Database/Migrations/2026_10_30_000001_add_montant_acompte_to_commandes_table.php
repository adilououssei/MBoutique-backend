<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acompte versé à la commande (préparation boucherie, dépôt pressing…) :
 * cumul des sommes déjà entrées en caisse. À l'encaissement, seul le reste
 * entre en caisse — docs/modules.md §Orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->decimal('montant_acompte', 12, 2)->default(0)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn('montant_acompte');
        });
    }
};

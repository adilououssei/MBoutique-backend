<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fonctionnalité « en option » pour un métier : désactivée par défaut, mais
 * chaque boutique peut l'activer elle-même (Paramètres du module, via
 * fonctionnalites_boutique). Ex. : les réparations en électronique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fonctionnalites_domaine', function (Blueprint $table) {
            $table->boolean('optionnelle')->default(false)->after('active_par_defaut');
        });
    }

    public function down(): void
    {
        Schema::table('fonctionnalites_domaine', fn (Blueprint $table) => $table->dropColumn('optionnelle'));
    }
};

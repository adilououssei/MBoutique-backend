<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrateur de la plateforme (équipe MBoutique) — docs/permissions.md §6.
 * Distinct des rôles de boutique : ce n'est pas un StoreUser. Accordé
 * uniquement par la commande `php artisan admin:creer`, jamais par l'API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->boolean('est_admin_plateforme')->default(false)->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropColumn('est_admin_plateforme');
        });
    }
};

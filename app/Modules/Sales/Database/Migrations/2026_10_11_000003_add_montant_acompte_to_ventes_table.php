<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vente à crédit (docs/sales.md §24) : part payée comptant à la vente.
 * Null pour une vente en espèces (tout est payé). L'annulation ne rembourse
 * en caisse que ce qui y est entré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->decimal('montant_acompte', 12, 2)->nullable()->after('montant_total');
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('montant_acompte');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Échange : le retour (décision `echange`) pointe vers la vente du nouvel article. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retours', function (Blueprint $table) {
            $table->foreignId('vente_echange_id')->nullable()->after('vente_id')->constrained('ventes')->nullOnDelete();
            // Écart rendu au client quand le nouvel article coûte moins cher.
            $table->decimal('montant_rendu', 12, 2)->default(0)->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('retours', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vente_echange_id');
            $table->dropColumn('montant_rendu');
        });
    }
};

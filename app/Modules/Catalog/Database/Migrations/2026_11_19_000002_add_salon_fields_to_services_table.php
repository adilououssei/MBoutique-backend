<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salon de beauté : photo et commission de la prestation ; produits
 * consommés par prestation (déduits du stock une seule fois, à
 * l'encaissement, et remis si la vente est annulée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
            // pourcentage | montant ; null : taux de l'employé
            $table->string('commission_type')->nullable()->after('duree_minutes');
            $table->decimal('commission_valeur', 12, 2)->nullable()->after('commission_type');
        });

        Schema::create('consommables_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->decimal('quantite', 12, 3);
            $table->timestamps();

            $table->unique(['service_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consommables_services');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['image', 'commission_type', 'commission_valeur']));
    }
};

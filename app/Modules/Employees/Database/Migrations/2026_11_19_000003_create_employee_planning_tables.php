<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planning du personnel (salon) : prestations que l'employé sait faire,
 * horaires par jour avec pause, congés et absences, taux de commission.
 * Rien de configuré = aucune contrainte (comportement d'avant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employes', function (Blueprint $table) {
            $table->decimal('taux_commission', 5, 2)->nullable()->after('periodicite_salaire');
        });

        Schema::create('services_employes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained('employes')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employe_id', 'service_id']);
        });

        Schema::create('horaires_employes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained('employes')->cascadeOnDelete();
            // 1 = lundi … 7 = dimanche (ISO) ; jour sans ligne = repos
            $table->unsignedTinyInteger('jour');
            $table->string('debut', 5);
            $table->string('fin', 5);
            $table->string('pause_debut', 5)->nullable();
            $table->string('pause_fin', 5)->nullable();
            $table->timestamps();

            $table->unique(['employe_id', 'jour']);
        });

        Schema::create('absences_employes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employe_id')->constrained('employes')->cascadeOnDelete();
            // conge | absence | maladie
            $table->string('type')->default('conge');
            $table->date('du');
            $table->date('au');
            $table->string('motif')->nullable();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['employe_id', 'du', 'au']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences_employes');
        Schema::dropIfExists('horaires_employes');
        Schema::dropIfExists('services_employes');
        Schema::table('employes', fn (Blueprint $table) => $table->dropColumn('taux_commission'));
    }
};

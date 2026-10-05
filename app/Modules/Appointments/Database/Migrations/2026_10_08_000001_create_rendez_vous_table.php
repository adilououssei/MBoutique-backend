<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendez-vous — docs/database.md §12 et docs/modules.md §Appointments.
 * Pas de contrainte SQL d'exclusion (non portable sous MySQL) : le
 * non-chevauchement par employé est garanti par AppointmentService, sous verrou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            // Nullable : « n'importe qui de disponible ».
            $table->foreignId('employe_id')->nullable()->constrained('employes')->restrictOnDelete();
            // Client enregistré OU simple nom/téléphone (réservation prise au téléphone).
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_client')->nullable();
            $table->string('telephone_client', 30)->nullable();
            $table->timestamp('debut_le');
            $table->timestamp('fin_le');
            // prevu, confirme, termine, annule, absent
            $table->string('statut');
            $table->text('notes')->nullable();
            $table->string('motif_annulation', 500)->nullable();
            // Vente encaissée pour ce rendez-vous (facultatif).
            $table->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['employe_id', 'debut_le']);
            $table->index(['boutique_id', 'debut_le']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendez_vous');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réservations de tables, enregistrées par le personnel (pas de réservation
 * publique). Une réservation confirmée rend sa table « réservée » à l'approche
 * de l'heure (ReservationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables_salle')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('nom_client');
            $table->string('telephone_client', 30)->nullable();
            $table->timestamp('prevue_le');
            $table->unsignedSmallInteger('nombre_personnes');
            // confirmee, arrivee, annulee, non_honoree
            $table->string('statut')->default('confirmee');
            $table->text('note')->nullable();
            $table->foreignId('commande_id')->nullable()->constrained('commandes')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['boutique_id', 'prevue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

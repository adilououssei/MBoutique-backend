<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Équipements (congélateurs, chambres froides, balances, scies…) et leur
 * historique d'entretiens, pannes et réparations — docs/modules.md §Equipment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('type');
            $table->string('marque')->nullable();
            $table->string('numero_serie')->nullable();
            $table->date('date_acquisition')->nullable();
            $table->string('statut')->default('en_service');
            // Entretien périodique : la prochaine échéance avance de la fréquence à chaque entretien.
            $table->unsignedSmallInteger('frequence_entretien_jours')->nullable();
            $table->date('dernier_entretien_le')->nullable();
            $table->date('prochain_entretien_le')->nullable();
            $table->string('note', 1000)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'actif']);
            $table->index('prochain_entretien_le');
        });

        Schema::create('interventions_equipement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipement_id')->constrained('equipements')->cascadeOnDelete();
            $table->string('type'); // entretien | panne | reparation
            $table->timestamp('survenue_le');
            $table->string('description', 1000);
            $table->decimal('cout', 12, 2)->nullable();
            $table->string('prestataire')->nullable();
            // Coût payé : la dépense correspondante (module Expenses), si elle a été enregistrée.
            $table->foreignId('depense_id')->nullable()->constrained('depenses')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipement_id', 'survenue_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions_equipement');
        Schema::dropIfExists('equipements');
    }
};

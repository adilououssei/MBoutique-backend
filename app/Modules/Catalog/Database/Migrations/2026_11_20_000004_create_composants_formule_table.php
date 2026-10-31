<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formules combinées (coupe + barbe, pack mariée…) : une prestation
 * `est_formule` avec son prix et sa durée globaux, composée d'autres
 * prestations (consommables et habilitations repris des composants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('est_formule')->default(false)->after('prix_variable');
        });

        Schema::create('composants_formule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('formule_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['formule_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composants_formule');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('est_formule'));
    }
};

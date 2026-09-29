<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fonctionnalites_domaine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domaine_activite_id')->constrained('domaines_activite')->cascadeOnDelete();
            $table->foreignId('fonctionnalite_id')->constrained()->cascadeOnDelete();
            $table->boolean('active_par_defaut')->default(true);
            $table->timestamps();

            $table->unique(['domaine_activite_id', 'fonctionnalite_id'], 'fonctionnalites_domaine_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fonctionnalites_domaine');
    }
};

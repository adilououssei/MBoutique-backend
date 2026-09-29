<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependances_fonctionnalites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fonctionnalite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depend_de_fonctionnalite_id')->constrained('fonctionnalites')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fonctionnalite_id', 'depend_de_fonctionnalite_id'], 'dependances_fonctionnalites_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependances_fonctionnalites');
    }
};

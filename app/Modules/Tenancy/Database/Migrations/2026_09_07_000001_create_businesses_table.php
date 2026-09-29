<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proprietaire_id')->constrained('utilisateurs')->restrictOnDelete();
            $table->string('nom');
            $table->string('raison_sociale')->nullable();
            $table->string('pays')->nullable();
            $table->string('devise', 3)->default('XOF');
            $table->string('fuseau_horaire')->default('UTC');
            $table->string('statut')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('proprietaire_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entreprises');
    }
};

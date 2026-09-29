<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('forfait_id')->constrained()->restrictOnDelete();
            $table->string('statut')->default('actif');
            $table->timestamp('fin_essai_le')->nullable();
            $table->timestamp('debut_periode_le')->nullable();
            $table->timestamp('fin_periode_le')->nullable();
            $table->timestamp('annule_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonnements');
    }
};

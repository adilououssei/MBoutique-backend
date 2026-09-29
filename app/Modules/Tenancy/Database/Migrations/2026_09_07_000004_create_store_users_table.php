<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs_boutique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utilisateur_id')->constrained()->restrictOnDelete();
            $table->string('statut')->default('actif');
            $table->foreignId('invite_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('invite_le')->nullable();
            $table->timestamp('rejoint_le')->nullable();
            $table->timestamps();

            $table->unique(['boutique_id', 'utilisateur_id']);
            $table->index('utilisateur_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utilisateurs_boutique');
    }
};

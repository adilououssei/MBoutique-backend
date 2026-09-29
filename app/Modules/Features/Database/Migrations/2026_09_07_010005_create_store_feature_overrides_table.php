<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fonctionnalites_boutique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fonctionnalite_id')->constrained()->cascadeOnDelete();
            $table->boolean('activee');
            $table->timestamps();

            $table->unique(['boutique_id', 'fonctionnalite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fonctionnalites_boutique');
    }
};

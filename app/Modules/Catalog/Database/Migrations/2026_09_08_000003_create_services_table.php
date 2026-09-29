<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('categorie_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('nom');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('prix', 12, 2);
            $table->unsignedInteger('duree_minutes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['boutique_id', 'slug']);
            $table->index('categorie_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};

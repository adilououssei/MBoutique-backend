<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forfaits', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('nom');
            $table->decimal('prix_mensuel', 12, 2)->default(0);
            $table->decimal('prix_annuel', 12, 2)->nullable();
            $table->unsignedInteger('max_boutiques')->nullable();
            $table->unsignedInteger('max_utilisateurs_par_boutique')->nullable();
            $table->unsignedInteger('max_produits_par_boutique')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forfaits');
    }
};

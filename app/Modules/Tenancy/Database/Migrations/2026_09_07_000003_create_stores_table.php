<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->cascadeOnDelete();
            // business_domain_id intentionally omitted: BusinessDomain (Features
            // module) is out of scope for this phase. See docs/roadmap.md Phase 2.
            $table->string('nom');
            $table->string('slug')->unique();
            $table->string('adresse')->nullable();
            $table->string('telephone')->nullable();
            $table->string('devise', 3)->default('XOF');
            $table->string('fuseau_horaire')->default('UTC');
            $table->string('statut')->default('active');
            $table->json('parametres')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('entreprise_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutiques');
    }
};

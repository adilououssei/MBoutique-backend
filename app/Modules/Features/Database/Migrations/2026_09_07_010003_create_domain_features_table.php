<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default_enabled')->default(true);
            $table->timestamps();

            $table->unique(['business_domain_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_features');
    }
};

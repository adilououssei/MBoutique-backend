<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled');
            $table->timestamps();

            $table->unique(['store_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_feature_overrides');
    }
};

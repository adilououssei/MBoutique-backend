<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_feature_id')->constrained('features')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['feature_id', 'depends_on_feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_dependencies');
    }
};

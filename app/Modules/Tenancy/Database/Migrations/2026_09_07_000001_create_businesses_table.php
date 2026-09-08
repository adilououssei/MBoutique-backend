<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('country')->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->string('timezone')->default('UTC');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};

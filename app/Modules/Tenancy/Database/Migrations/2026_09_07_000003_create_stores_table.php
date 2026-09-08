<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // business_domain_id intentionally omitted: BusinessDomain (Features
            // module) is out of scope for this phase. See docs/roadmap.md Phase 2.
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('currency', 3)->default('XOF');
            $table->string('timezone')->default('UTC');
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};

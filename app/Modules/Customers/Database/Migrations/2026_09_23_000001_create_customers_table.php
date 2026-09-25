<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('company_name')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // SoftDeletes, not a hard delete — a Customer may already be
            // referenced by Sale.customer_id once Sales exists; see
            // docs/customers.md §"Suppression".
            $table->softDeletes();

            // No uniqueness on name/phone/email, deliberately — see
            // docs/customers.md §"Duplication". store_id is already
            // indexed by the foreign key itself, no separate index needed.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

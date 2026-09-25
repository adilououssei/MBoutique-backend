<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // Signed, DECIMAL(12,2), never float — see docs/cash-register.md §"Argent et précision".
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reason')->nullable();
            // Prepared for a future Sale reference — nothing writes it
            // yet in this phase, same pattern as stock_movements.
            $table->nullableMorphs('reference');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Append-only ledger: no update/delete endpoint anywhere in
            // this module — see docs/cash-register.md §"Immutabilité".
            $table->index(['cash_register_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
